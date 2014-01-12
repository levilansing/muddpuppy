<?php
//======================================================================================================================
// This file is part of the Mudpuppy PHP framework, released under the MIT License. See LICENSE for full details.
//======================================================================================================================

namespace Mudpuppy;
use App\Config;

defined('MUDPUPPY') or die('Restricted');

// dataTypes defined in database.php
define("DATAFLAG_LOADED", 1);
define("DATAFLAG_CHANGED", 2);
define("DATAFLAG_NOTNULL", 4);

class DataValue {

	var $dataType;
	var $value;
	var $flags;
	var $maxLength;

	// set flag to loaded if sending value
	function __construct($dataType, $default = null, $notNull = false, $maxLength = 0) {
		$this->dataType = $dataType;
		$this->value = $default;
		$this->flags = (is_null($default) ? 0 : DATAFLAG_CHANGED) | ($notNull ? DATAFLAG_NOTNULL : 0);
		$this->maxLength = $maxLength;
	}

	function isEmpty() {
		return !($this->flags & (DATAFLAG_LOADED | DATAFLAG_CHANGED));
	}

	function isChanged() {
		return $this->flags & DATAFLAG_CHANGED;
	}

	function isLoaded() {
		return $this->flags & DATAFLAG_LOADED;
	}

}

class DataLookup {

	var $column;
	var $values = array();
	var $type;

	function __construct($type, $column) {
		$this->type = $type;
		$this->column = $column;
	}

	/**
	 * called from DataObject::__get($column)
	 * @param DataObject $dataObject the current data object
	 * @return DataObject
	 */
	function &performLookup($dataObject) {
		$id = $dataObject->{$this->column};
		if (isset($this->values[$id])) {
			return $this->values[$id];
		}

		$this->values[$id] = call_user_func($this->type . '::get', $id);
		return $this->values[$id];
	}

	/**
	 * @param DataObject $dataObject
	 * @param DataObject $value
	 */
	function setLookup($dataObject, $value) {
		if (is_int($value)) {
			$dataObject->{$this->column} = $value;
		} else if ($value instanceof DataObject) {
			$dataObject->{$this->column} = $value->id;
			$this->values[$value->id] = $value;
		}
	}

	/**
	 * called from DataObject::__unset($column)
	 */
	function clearLookup($dataObject, $clearAll = false) {
		$id = $dataObject->{$this->column};
		if ($clearAll) {
			$this->values = array();
		} else {
			unset($this->values[$id]);
		}
	}

}

abstract class DataObject implements \JsonSerializable {

	/** @var DataValue[] $_lookup */
	protected $_data; // array of DataValue; key = col name
	/** @var DataValue[] $_defaults */
	protected static $_defaults = array();
	/** @var DataLookup[][] $_lookup */
	protected static $_lookups = array();
	/** @var array $_extra */
	protected $_extra; // array of extra data found when loading rows.  this way queries can pull additional data from joined tables

	/**
	 * Create a new data object and initialize the data with $row
	 * @param $row array OR int id
	 */
	function __construct($row = null) {
		$this->_data = array();
		$this->_extra = array();
		if (!isset(self::$_defaults[$this->getObjectName()])) {
			// maintain the default data (column values) statically
			self::$_defaults[$this->getObjectName()] = array();
			self::$_lookups[$this->getObjectName()] = array();
			$this->loadDefaults();
		}

		// clone from the default data- much faster than reloading the column values every time
		$def =& self::$_defaults[$this->getObjectName()];
		foreach ($def as $k => &$d) {
			$this->_data[$k] = clone $d;
		}

		// load the data or set the id
		if (is_array($row)) {
			$this->loadFromRow($row);
		} else if (is_int($row)) {
			$this->setValue('id', $row);
		}
	}

	/////////////////////////////////////////////////////////////////
	// these functions are to be implemented by child class

	// should load column names with default data (id column required)
	// ex: $this->createColumn('id',DATATYPE_INT);
	abstract protected function loadDefaults();

	//
	//////////////////////////////////////////////////////////////////

	function __clone() {
		foreach ($this->_data as $k => $data) {
			$this->_data[$k] = clone $data;
		}
	}

	//////////////////////////////////////////
	// overridable functions

	protected function getObjectName() {
		return get_called_class();
	}

	/**
	 * @throws MudpuppyException default implementation
	 * @returns string
	 */
	public static function getTableName() {
		throw new MudpuppyException('Concrete DataObjects must override the static method getTableName');
	}

	//////////////////////////////////////////
	// the core

	/**
	 * ONLY CALL FROM loadDefaults() function
	 * Note: createColumn functions are now autogenerated from database schema,
	 * so we don't need to manage the default value manually anymore.
	 * Default value still exists for readability.
	 *
	 * @param $col
	 * @param $type
	 * @param null $dbDefault IGNORED allowing DB to set default value upon commit
	 * @param bool $notNull
	 */
	protected function createColumn($col, $type, $dbDefault = null, $notNull = false) {
		self::$_defaults[$this->getObjectName()][$col] = new DataValue($type, $dbDefault, $notNull);
	}

	/**
	 * ONLY CALL FROM loadDefaults() function
	 * Change the default value for a column.
	 *
	 * @param mixed $column
	 * @param string $default =null
	 */
	protected function updateColumnDefault($column, $default = null) {
		$col = self::$_defaults[$this->getObjectName()][$column];
		self::$_defaults[$this->getObjectName()][$column] = new DataValue($col->dataType, $default, $col->flags & DATAFLAG_NOTNULL);
	}

	function getColumns() {
		return array_keys($this->_data);
	}

	function createLookup($column, $name, $type) {
		self::$_lookups[$this->getObjectName()][$name] = new DataLookup($type, $column);
	}

	/**
	 * get the id of this object
	 * @return int
	 */
	function getId() {
		return (int)$this->id;
	}

	/**
	 * save changes to database (if there are any)
	 * update id if this is an insert
	 * @return bool
	 */
	function save() {
		$id = $this->getId();
		$fields = array();
		foreach ($this->_data as $col => &$value) {

			// @todo for JSON, encode json object and check if it has changed instead of requiring mark change

			if (($value->flags & DATAFLAG_CHANGED)) // only save/update values that have been changed
			{
				$fields[] = new DBColumnValue($col, $value->dataType, $value->value);
			}
		}
		if (sizeof($fields) == 0 && $id != 0) {
			return true;
		}

		if ($id != 0) {
			// update
			if (!App::getDBO()->update($this->getTableName(), $fields, "id=$id")) {
				return false;
			}
		} else {
			// save new (insert)
			if ($id = App::getDBO()->insert($this->getTableName(), $fields)) {
				$this->_data['id']->value = $id;
				$this->_data['id']->flags = DATAFLAG_LOADED;
			} else {
				return false;
			}
		}

		// if we got here, the save was successful

		foreach ($this->_data as &$value) {
			if (($value->flags & DATAFLAG_CHANGED)) // we just saved/updated values that were changed
			{
				$value->flags &= ~DATAFLAG_CHANGED;
			} // remove changed flag
		}

		return true;
	}

	function copy() {
		$copy = clone $this;
		$copy->id = 0;
		foreach ($copy->_data as &$value) {
			$value->flags |= DATAFLAG_CHANGED;
		} // set changed flags

		return $copy;
	}

	function reload() {
		$fields = array();
		foreach ($this->_data as $col => $value) {
			if ($value->flags & DATAFLAG_CHANGED) // only reload values that have been changed
			{
				$fields[] = $col;
			}
		}

		if (sizeof($fields) > 0) {
			$this->load($fields);
		}
	}

	function load($cols = array()) {
		if (!is_array($cols)) {
			$cols = array($cols);
		}
		$id = $this->getId();
		if (Config::$debug) {
			// make sure we have an id
			if ($id == 0) {
				throw new MudpuppyException("Assertion Error: Cannot load a data object (" . get_called_class() . ") with an id of 0");
			}

			// verify we asked only for existing columns
			foreach ($cols as $col) {
				if (!isset($this->_data[$col])) {
					throw new MudpuppyException("Column '$col' of DataObject '" . $this->getObjectName() . "' not defined in load().");
				}
			}
		}

		if (sizeof($cols) == 0) {
			$cols = array_keys($this->_data);
		} // load all cols

		$bNeedLoad = false;
		foreach ($cols as $col) {
			$f = $this->_data[$col]->flags;
			if (!($f & DATAFLAG_LOADED) || ($f & DATAFLAG_CHANGED)) {
				$bNeedLoad = true;
				break;
			}
		}

		if ($bNeedLoad) {
			$db = App::getDBO();
			$result = $db->select($cols, $this->getTableName(), "id=$id");

			if ($result && $row = $db->fetchRowAssoc($result)) {
				$this->loadFromRow($row);
			} else {
				return false;
			}
		}
		return true;
	}

	function loadMissing() {
		$cols = array();
		foreach ($this->_data as $k => $data) {
			if ($data->isEmpty()) {
				$cols[] = $k;
			}
		}
		if (sizeof($cols) == 0) {
			return true;
		}
		return $this->load($cols);
	}

	function exists() {
		$id = $this->getId();
		if ($id == 0) {
			return false;
		}
		$db = App::getDBO();
		$db->select(array('id'), $this->getTableName(), "id=$id");
		return $db->numRows() > 0;
	}

	function delete() {
		if ($this->getId() == 0) {
			return true;
		}

		$result = App::getDBO()->delete($this->getTableName(), "id=" . $this->getId());
		// TODO: log some debug if needed
		if ($result) {
			// if we got here, the delete was successful
			$this->id = 0; // set the id to 0 because it's been deleted
			foreach ($this->_data as &$value) {
				if ($value->flags & DATAFLAG_LOADED) {
					$value->flags &= ~DATAFLAG_LOADED;
					$value->flags |= DATAFLAG_CHANGED;
				} // flag all valid data as changed & not loaded
			}
		}

		return $result ? true : false;
	}

	function loadFromRow($row) {
		if (!$row) {
			return;
		}

		$setChanged = (isset($row['id']) && $row['id']) ? false : true;

		foreach ($row as $col => &$value) {
			if (isset($this->_data[$col])) {
				$data = & $this->_data[$col];
				if (is_null($value)) {
					$data->value = null;
				} else {
					if (($data->dataType == DATATYPE_DATETIME || $data->dataType == DATATYPE_DATE) && is_string($value)) {
						$data->value = Database::readDate($value, $data->dataType == DATATYPE_DATETIME);
					} else {
						if ($data->dataType == DATATYPE_INT && $value === (string)(int)$value) {
							$data->value = (int)$value;
						} else {
							$data->value =& $value;
						}
					}
				}
				$data->flags &= ~DATAFLAG_CHANGED;
				$data->flags |= DATAFLAG_LOADED;
				if ($setChanged) {
					$data->flags |= DATAFLAG_CHANGED;
				}
			} else {
				$this->_extra[$col] =& $value;
			}
		}

		// assume we loaded the id (or from the id)
		if ($this->_data['id']->value > 0) {
			$this->_data['id']->flags &= ~DATAFLAG_CHANGED;
			$this->_data['id']->flags |= DATAFLAG_LOADED;
		}
	}

	/**
	 * @param int $id
	 * @return DataObject|null
	 */
	public static function get($id) {
		if (!$id) {
			return null;
		}

		/** @var DataObject $objectClass */
		$objectClass = get_called_class();

		$statement = App::getDBO()->prepare('SELECT * FROM ' . $objectClass::getTableName() . ' WHERE id=?');
		$statement->bindValue(1, $id, \PDO::PARAM_INT);
		$result = App::getDBO()->query();
		if ($result && ($row = $result->fetch(\PDO::FETCH_ASSOC))) {
			return new $objectClass($row);
		}
		return null;
	}

	/**
	 * @param int $start
	 * @param int $limit
	 * @return DataObject[]
	 */
	public static function getAll($start, $limit) {
		return self::getByFields(null, '1', $start, $limit);
	}

	/**
	 * @param array $fieldSet in format { fieldName => value }
	 * @param string $condition conditional logic in addition to $fieldSet
	 * @param int $start
	 * @param int $limit
	 * @return DataObject[]
	 */
	public static function getByFields($fieldSet, $condition = '', $start = 0, $limit = 0) {
		$objectClass = get_called_class();
		// create an empty data object to read structure
		$emptyObject = new $objectClass();
		$fieldSet = $fieldSet == null ? array() : $fieldSet;

		$db = App::getDBO();

		// build query
		/** @var DataObject $objectClass */
		$query = 'SELECT * FROM ' . $objectClass::getTableName() . ' WHERE ';
		if (!empty($fieldSet)) {
			$query .= '(`' . implode('`=? AND `', array_keys($fieldSet)) . '`=?' . ')';
		}
		if (!empty($condition)) {
			$query .= (empty($fieldSet) ? '' : ' AND ') . $condition;
		}

		if (empty($fieldSet) && empty($condition)) {
			$query .= '1=1';
		}

		if ($start != 0 || $limit != 0) {
			$query .= ' LIMIT ' . (int)$start . ',' . (int)$limit;
		}

		$statement = $db->prepare($query);

		// bind params
		$i = 1;
		foreach ($fieldSet as $field => $value) {
			$type = \PDO::PARAM_STR;
			if ($emptyObject->_data[$field]->dataType == DATATYPE_BOOL) {
				$type = \PDO::PARAM_BOOL;
			} else if ($emptyObject->_data[$field]->dataType < DATATYPE_INT) {
				$type = \PDO::PARAM_INT;
			}
			$statement->bindValue($i++, $value, $type);
		}

		// query
		$result = $db->query();
		$objects = array();
		while ($result && ($row = $result->fetch(\PDO::FETCH_ASSOC))) {
			$objects[] = new $objectClass($row);
		}
		return $objects;
	}

	/**
	 * Fetch by an id or key value pair map
	 * @param int|array $criteria
	 * @throws MudpuppyException
	 * @return \Mudpuppy\DataObject[]
	 */
	public static function fetch($criteria) {
		if (is_int($criteria)) {
			return [self::get($criteria)];
		}
		if (is_array($criteria)) {
			return self::getByFields($criteria);
		}
		throw new MudpuppyException("Unrecognized \$options type");
	}

	/**
	 * Fetch by an id or key value pair map, but only return the first result or null
	 * @param $criteria
	 * @return DataObject|DataObject[]|null
	 */
	public static function fetchOne($criteria) {
		$result = self::fetch($criteria);
		if (is_array($result)) {
			if (count($result) > 0) {
				return $result[0];
			} else {
				return null;
			}
		}
		return $result;
	}

	function getDate($col, $format, $default = null) {
		if ($this->$col) {
			return date($format, $this->$col);
		}
		return $default;
	}

	function setDate($col, $datestring, $default = null) {
		if (strlen($datestring) > 0) {
			$this->$col = strtotime($datestring);
		} else {
			$this->$col = $default;
		}
	}

	public function markChanged($col) {
		if (isset($this->_data[$col])) {
			$data =& $this->_data[$col];
			$data->flags |= DATAFLAG_CHANGED;
		}
	}

	// "operator overloading"
	public function &__get($col) {
		if (isset($this->_data[$col])) {
			$dataValue = $this->_data[$col];
			if ($dataValue->dataType == DATATYPE_JSON) {
				if (is_string($dataValue->value)) {
					$dataValue->value = json_decode($dataValue->value, true);
				}
			}
			return $dataValue->value;
		} else {
			/** @var DataLookup[] $lookups */
			$lookups = self::$_lookups[$this->getObjectName()];
			if (isset($lookups[$col])) {
				return $lookups[$col]->performLookup($this);
			}
			return $this->_extra[$col];
		}
	}

	function &getValue($col) {
		return $this->__get($col);
	}

	// "operator overloading"
	public function __set($col, $value) {
		if (!isset($this->_data[$col])) {
			/** @var DataLookup[] $lookups */
			$lookups = self::$_lookups[$this->getObjectName()];
			if (isset($lookups[$col])) {
				$lookups[$col]->setLookup($this, $value);
			}
			$this->_extra[$col] = $value;
			return;
		}
		$data =& $this->_data[$col];
		if (!($data->flags & DATAFLAG_LOADED) || $data->value != $value || $data->dataType == DATATYPE_JSON) {
			$data->value = $value;
			$data->flags |= DATAFLAG_CHANGED;
		}
	}

	function setValue($col, $value) {
		$this->__set($col, $value);
	}

	public function __unset($key) {
		if (isset($this->_data[$key])) {
			$this->_data[$key]->value = null; // clear the value
			$this->_data[$key]->flags &= ~DATAFLAG_CHANGED; // don't want to overwrite it in db
		} else {
			if (isset($this->_lookup[$key])) {
				self::$_lookups[$this->getObjectName()][$key]->clearLookup($this);
			} else {
				unset($this->_extra[$key]);
			}
		}
	}

	function clearValue($col) {
		$this->__unset($col);
	}

	public function __isset($key) {
		return $this->hasValue($key) || isset($this->_extra[$key]);
	}

	function hasValue($col) {
		//if(App::isDebug() && !isset($this->_data[$col]))
		//	throw new Exception("Column '$col' of DataObject '".$this->getObjectName()."' is not a valid column.");

		return isset($this->_data[$col]) && ($this->_data[$col]->flags & DATAFLAG_LOADED);
	}

	function hasValueChanged($col) {
		return isset($this->_data[$col]) && ($this->_data[$col]->flags & DATAFLAG_CHANGED);
	}

	public function jsonSerialize() {
		return $this->toArray();
	}

	/**
	 * creates an associative array from the data object
	 * after running each value through htmlentities()
	 * @return array
	 */
	public function htmlEntities() {
		$data = $this->toArray();
		array_walk($data, function (&$value, $key) {
			$value = htmlentities($value);
		});
		return $data;
	}

	/**
	 * Convert data object into a JSON friendly array
	 *
	 * @param string $dateFormat or null to use the date format from Config
	 * @param array $filter list of keys to include
	 * @return array
	 */
	function &toArray($dateFormat = null, $filter = null) {
		if (empty($dateFormat)) {
			$dateFormat = Config::$dateFormat;
		}
		$a = array();
		foreach ($this->_data as $k => $v) {
			if ($filter && !in_array($k, $filter)) {
				continue;
			}
			$dataType = $v->dataType;
			if ($v->value === null) {
				$a[$k] = null;
			} else if ($dataType == DATATYPE_DATETIME || $dataType == DATATYPE_DATE) {
				if ($v->value == null || $v->value == '') {
					$a[$k] = null;
				} else {
					$a[$k] = date($dateFormat, $v->value);
				}
			} else if ($dataType == DATATYPE_JSON) {
				if (is_string($v->value)) {
					$v->value = json_decode($v->value, true);
				}
				$a[$k] = $v->value;
			} else if ($dataType == DATATYPE_BOOL) {
				$a[$k] = (bool)$v->value;
			} else if ($dataType <= DATATYPE_INT) {
				$a[$k] = (int)$v->value;
			} else if ($dataType <= DATATYPE_DOUBLE) {
				$a[$k] = (double)$v->value;
			} else {
				$a[$k] = $v->value;
			}
		}

		foreach ($this->_extra as $k => $v) {
			if ($filter && !in_array($k, $filter)) {
				continue;
			}
			if (is_object($v) && $v instanceof DataObject) {
				/** @var DataObject $v */
				$a[$k] = $v->toArray($dateFormat);
			} else if (is_array($v)) {
				$a[$k] = self::objectListToArrayList($v, $dateFormat);
			} else {
				$a[$k] = $v;
			}
		}
		return $a;
	}

	/**
	 * Convert an array of data objects to an array of arrays
	 *
	 * @param array $array of data objects (can be nested in arrays)
	 * @param null $dateFormat
	 * @return array
	 */
	public static function objectListToArrayList($array, $dateFormat = null) {
		if (empty($dateFormat)) {
			$dateFormat = Config::$dateFormat;
		}
		if (!is_array($array)) {
			if (is_object($array) && $array instanceof DataObject) {
				/** @var DataObject $array */
				return $array->toArray($dateFormat);
			}
			return array();
		}

		$result = array();
		foreach ($array as $key => $object) {
			if (is_array($object)) {
				$result[$key] = self::objectListToArrayList($object, $dateFormat);
			} else if (is_object($object) && $object instanceof DataObject) {
				/** @var DataObject $object */
				$result[$key] = $object->toArray($dateFormat);
			} else {
				$result[$key] = $object;
			}
		}
		return $result;
	}

	/**
	 * Generates a structure definition recognizable by the Module/API system
	 * @return array structure definition
	 */
	public static function getStructureDefinition() {
		$objectClass = get_called_class();
		$objectDef =& self::$_defaults[$objectClass];
		$definition = array();

		/** @var DataValue $d */
		foreach ($objectDef as $k => &$d) {
			$type = 'int';
			if ($d->dataType == DATATYPE_DATETIME || $d->dataType == DATATYPE_DATE) {
				$type = 'date';
			} else if ($d->dataType == DATATYPE_BOOL) {
				$type = 'bool';
			} else if ($d->dataType <= DATATYPE_INT) {
				$type = 'int';
			} else if ($d->dataType <= DATATYPE_DOUBLE) {
				$type = 'double';
			} else if ($d->dataType == DATATYPE_JSON) {
				$type = 'string';
			} else {
				$type = 'string';
			}
			$required = $d->flags & DATAFLAG_NOTNULL;
			$definition[$k] = array(
				'type' => $type,
				'required' => $required
			);
		}
		return $definition;
	}

}

?>