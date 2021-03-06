<?php 
namespace Maphper\DataSource;
class Database implements \Maphper\DataSource {
	private $table;
	private $cache = [];
	private $primaryKey;
	private $resultClass = 'stdClass';
	private $fields = '*';
	private $defaultSort;
	private $resultCache = [];	
	private $errors = [];
	private $alterDb = false;
	
	public function __construct($db, $table, $primaryKey = 'id', array $options = []) {
		if ($db instanceof \PDO) $this->adapter = $this->getAdapter($db);
		else $this->adapter = $db;

		$this->table = $table;
		if (!is_array($primaryKey)) $primaryKey = [$primaryKey];
		$this->primaryKey = $primaryKey;
		
		if (isset($options['resultClass'])) $this->resultClass = $options['resultClass'];

		if (isset($options['fields'])) $this->fields = implode(',', array_map([$this->adapter, 'quote'], $options['fields']));

		$this->defaultSort = (isset($options['defaultSort'])) ? $options['defaultSort'] : implode(', ', $this->primaryKey);

		if (isset($options['editmode'])) $this->alterDb = $options['editmode'];		
	}

	private function getAdapter(\PDO $pdo) {
		$adapter = '\\Maphper\\DataSource\\' . $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) . 'Adapter';
		return new $adapter($pdo);
	}
	
	public function getPrimaryKey() {
		return $this->primaryKey;
	}
	
	public function createNew() {
		return (is_callable($this->resultClass)) ? call_user_func($this->resultClass) : new $this->resultClass;
	}
	
	public function deleteById($id) {
		$this->adapter->delete($this->table, [$this->primaryKey[0] . ' = :id'], [':id' => $id], 1);		
		unset($this->cache[$id]);
	}
	
	private function wrap($result) {
		$newResult = [];			
		//This allows writing to private properties in result classes
		$writeClosure = function($field, $value) {
			$this->$field = $value;
		};
		
		foreach ($result as $obj) {
			$new = $this->createNew();
			$write = $writeClosure->bindTo($new, $new);
			foreach ($obj as $key => $value) $write($key, $this->processDates($value));
			$this->cache[$obj->{$this->primaryKey[0]}] = $new;
			$newResult[] = $new;
		}
		return $newResult;
	}
	
	private function processDates($obj) {
		if (is_array($obj) || is_object($obj)) foreach ($obj as &$o) $o = $this->processDates($o);		
		if (is_string($obj) && is_numeric($obj[0]) && strlen($obj) <= 20) {
			try {
				$date = new \DateTime($obj);
				if ($date->format('Y-m-d H:i:s') == substr($obj, 0, 20)) $obj = $date;
			}
			catch (\Exception $e) {}
		}
		return $obj;
	}
	
	public function getErrors() {
		return $this->errors;
	}

	public function findById($id) {
		if (!isset($this->cache[$id])) {
			$result = $this->adapter->select($this->table, [$this->getPrimaryKey()[0] . ' = :id'], [':id' => $id]);
			if (isset($result[0])) 	$this->cache[$id] = $this->wrap($result)[0];
			else return null;
		}
		return $this->cache[$id];
	}

	private function buildFindQuery($fields, $mode){
		$args = [];
		$sql = [];	
		
		foreach ($fields as $key => $value) {
			if (is_numeric($key) && is_array($value)) {
				$result = $this->buildFindQuery($value, $key);
				foreach ($result['args'] as $arg_key => $arg) $args[$arg_key] = $arg;
				foreach ($result['sql'] as $arg) $sql[] = $arg;
				continue;
			}
			else if (\Maphper\Maphper::FIND_BETWEEN & $mode) {
				$sql[] = $this->adapter->quote($key) . '>= :' . $key . 'from';
				$sql[] = $this->adapter->quote($key) . ' <= :' . $key . 'to';
			
				$args[$key . 'from'] = $value[0];
				$args[$key . 'to'] = $value[1];
				continue;
			}
			else if (!is_numeric($key) && is_array($value)) {
				$inSql = [];
				for ($i = 0; $i < count($value); $i++) {
					$args[$key . $i] = $value[$i];
					$inSql[] = ':' . $key . $i;
				}
				if (count($inSql) == 0) return [];
				else $sql[] = $this->adapter->quote($key) . ' IN ( ' .  implode(', ', $inSql) . ')';
				continue;
			}			
			else if (\Maphper\Maphper::FIND_EXACT & $mode) $operator = '=';
			else if (\Maphper\Maphper::FIND_LIKE & $mode) {
				$operator = 'LIKE';
				$value = '%' . $value . '%';
			}
			else if (\Maphper\Maphper::FIND_STARTS & $mode) {
				$operator = 'LIKE';
				$value = $value . '%';
			}
			else if (\Maphper\Maphper::FIND_NOCASE & $mode) {
				$operator = 'LIKE';
			}
			else if (\Maphper\Maphper::FIND_BIT & $mode) $operator = '&';
			else if (\Maphper\Maphper::FIND_GREATER & $mode) $operator = '>';
			else if (\Maphper\Maphper::FIND_LESS & $mode) $operator = '<';
			else if (\Maphper\Maphper::FIND_NOT & $mode) $operator = '!=';
			
			$args[$key] = $value;
			$sql[] = $this->adapter->quote($key) . ' ' . $operator . ' :' . $key;
		}
		
		if (\Maphper\Maphper::FIND_OR & $mode) $query = implode(' OR  ', $sql);
		else $query = implode(' AND ', $sql);
		
		return ['args' => $args, 'sql' => [$query]];
	}

	public function findAggregate($function, $field, $group = null, array $criteria = [], array $options = []) {
		//Cannot count/sum/max multiple fields, pick the first one. This should only come into play when trying to count() a mapper with multiple primary keys
		if (is_array($field)) $field = $field[0];
		
		$query = $this->buildFindQuery($criteria, \Maphper\Maphper::FIND_EXACT | \Maphper\Maphper::FIND_AND);
		$args = $query['args'];
		$sql = $query['sql'];
			
		return $this->adapter->aggregate($this->table, $function, $field, $sql, $args, $group);	
	}
	
	public function findByField(array $fields, $options = []) {
		$cacheId = md5(serialize(func_get_args()));	
		if (!isset($this->resultCache[$cacheId])) {
			$query = $this->buildFindQuery($fields, \Maphper\Maphper::FIND_EXACT | \Maphper\Maphper::FIND_AND);
			$args = $query['args'];
			$sql = $query['sql'];
	
			if ($sql[0] == '') $sql = [];
			
			$limit = (isset($options['limit'])) ? $options['limit'] : null;
			$offset = (isset($options['offset'])) ? $options['offset'] : '';
	
			$order = (!isset($options['order'])) ? $this->defaultSort : $order = $options['order'];
			$this->resultCache[$cacheId] = $this->wrap($this->adapter->select($this->table, $sql, $args, $order, $limit, $offset));
		}
		return $this->resultCache[$cacheId];
	}	

	
	public function deleteByField(array $fields, array $options = [], $mode = null) {
		if ($mode == null) $mode = \Maphper\Maphper::FIND_EXACT | \Maphper\Maphper::FIND_AND;
	
		$query = $this->buildFindQuery($fields, $mode);
		$args = $query['args'];
		$sql = $query['sql'];		
	
		if ($sql[0] == '')  $where = '';
		else if (\Maphper\Maphper::FIND_OR & $mode) $where = ' WHERE ' . implode(' OR ', $sql);
		else $where = ' WHERE ' . implode(' AND ', $sql);			
	
		if (isset($options['limit']) != null) $limit = ' LIMIT ' . $options['limit'];
		else $limit = '';
	
		$this->adapter->delete($this->table, $sql, $args, $limit);		
		//Clear the cache
		$this->cache = [];
		$this->resultCache = [];
	}
	
	public function save($data) {
		$pk = $this->primaryKey;	

		$new = false;
		foreach ($pk as $k) {
			if (!isset($data->$k) || $data->$k == '') $data->$k = null;
		}		
		//Extract private properties from the object
		$readClosure = function() {
			$data = new \stdClass;
			foreach ($this as $k => $v)	$data->$k = $v;
			return $data;
		};
		$read = $readClosure->bindTo($data, $data);
		$writeData = $read();			

		try {
			$result = $this->adapter->insert($this->table, $this->primaryKey, $writeData);
		}
		catch (\PDOException $e) {
			$result = null;
		}
		
		if ($this->alterDb) {
			$errors = $this->adapter->getErrors();
			$pk = $this->primaryKey[0];
			if ($result) $writeData->pk = $data->$pk = $result;
			if (count($errors) > 0) {
				$this->adapter->alterDatabase($this->table, $this->primaryKey, $writeData);
				$this->save($writeData);
			}
		}
		
		//TODO: This will error if the primary key is a private field	
		if ($new && count($this->primaryKey) == 1) $data->{$this->primaryKey} = $this->db->lastInsertId();
		//Something has changed, clear any cached results as they may now be incorrect
		$this->resultCache = [];
		$this->cache[$this->primaryKey[0]] = $data;
	}		
	
}