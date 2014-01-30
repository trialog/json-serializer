<?php
namespace Zumba\Util;

use ReflectionClass;
use ReflectionException;
use SplObjectStorage;
use Zumba\Exception\JsonSerializerException;

class JsonSerializer
{

	const CLASS_IDENTIFIER_KEY = '@type';

	/**
	 * Storage for object
	 *
	 * Used for recursion
	 *
	 * @var SplObjectStorage
	 */
	protected $objectStorage;

	/**
	 * Object mapping for recursion
	 *
	 * @var array
	 */
	protected $objectMapping = array();

	/**
	 * Object mapping index
	 *
	 * @var integer
	 */
	protected $objectMappingIndex = 0;

	/**
	 * Serialize the value in JSON
	 *
	 * @param mixed $value        	
	 * @return string JSON encoded
	 * @throws Zumba\Exception\JsonSerializerException
	 */
	public function serialize($value, $objectUnserializable = true)
	{
		$this->reset();
		return json_encode($this->serializeData($value, $objectUnserializable));
	}

	/**
	 * Unserialize the value from JSON
	 *
	 * @param string $value        	
	 * @return mixed
	 */
	public function unserialize($value)
	{
		$this->reset();
		return $this->unserializeData(json_decode($value, true));
	}

	/**
	 * Parse the data to be json encoded
	 *
	 * @param mixed $value        	
	 * @return mixed
	 * @throws Zumba\Exception\JsonSerializerException
	 */
	protected function serializeData($value, $objectUnserializable = true)
	{
		if (is_scalar($value) || $value === null) {
			return $value;
		}
		if (is_resource($value)) {
			throw new JsonSerializerException('Resource is not supported in JsonSerializer');
		}
		if (is_array($value)) {
			return array_map(array(
				$this,
				__FUNCTION__
			), $value);
		}
		if ($value instanceof \Closure) {
			throw new JsonSerializerException('Closures are not supported in JsonSerializer');
		}
		return $this->serializeObject($value, $objectUnserializable);
	}

	/**
	 * Extract the data from an object
	 *
	 * @param object $value        	
	 * @return array
	 */
	protected function serializeObject($value, $objectUnserializable = true)
	{
		$ref = new ReflectionClass($value);
		
		if ($this->objectStorage->contains($value)) {
			return array(
				static::CLASS_IDENTIFIER_KEY => '@' . $this->objectStorage[$value]
			);
		}
		$this->objectStorage->attach($value, $this->objectMappingIndex ++);
		
		$paramsToSerialize = $this->getObjectProperties($ref, $value);
		$data = array();
		if ($objectUnserializable) {
			$data[static::CLASS_IDENTIFIER_KEY] = $ref->getName();
		}
		$data += array_map(array(
			$this,
			'serializeData'
		), $this->extractObjectData($value, $ref, $paramsToSerialize));
		return $data;
	}

	/**
	 * Return the list of properties to be serialized
	 *
	 * @param ReflectionClass $ref        	
	 * @param object $value        	
	 * @return array
	 */
	protected function getObjectProperties($ref, $value)
	{
		if (method_exists($value, '__sleep')) {
			return $value->__sleep();
		}
		
		$props = array();
		foreach ($ref->getProperties() as $prop) {
			$props[] = $prop->getName();
		}
		return array_unique(array_merge($props, array_keys(get_object_vars($value))));
	}

	/**
	 * Extract the object data
	 *
	 * @param object $value        	
	 * @param ReflectionClass $ref        	
	 * @param array $properties        	
	 * @return array
	 */
	protected function extractObjectData($value, $ref, $properties)
	{
		$data = array();
		foreach ($properties as $property) {
			try {
				$method = 'get' . ucfirst($property);
				$methodBoolean = 'is' . ucfirst($property);
				if (method_exists($value, $method)) {
					$data[$property] = $value->{$method}();
				}
				elseif (method_exists($value, $methodBoolean)) {
					$data[$property] = $value->{$methodBoolean}();
				}
				else {
					$propRef = $ref->getProperty($property);
					$propRef->setAccessible(true);
					$data[$property] = $propRef->getValue($value);
				}
			} catch (ReflectionException $e) {
				$data[$property] = $value->$property;
			}
		}
		return $data;
	}

	/**
	 * Parse the json decode to convert to objects again
	 *
	 * @param mixed $value        	
	 * @return mixed
	 */
	protected function unserializeData($value)
	{
		if (is_scalar($value) || $value === null) {
			return $value;
		}
		return isset($value[static::CLASS_IDENTIFIER_KEY]) ? $this->unserializeObject($value) : array_map(array(
			$this,
			__FUNCTION__
		), $value);
	}

	/**
	 * Convert the serialized array into an object
	 *
	 * @param aray $value        	
	 * @return object
	 * @throws Zumba\Exception\JsonSerializerException
	 */
	protected function unserializeObject($value)
	{
		$className = $value[static::CLASS_IDENTIFIER_KEY];
		unset($value[static::CLASS_IDENTIFIER_KEY]);
		
		if ($className[0] === '@') {
			$index = substr($className, 1);
			return $this->objectMapping[$index];
		}
		
		if (! class_exists($className)) {
			throw new JsonSerializerException('Unable to find class ' . $className);
		}
		$ref = new ReflectionClass($className);
		$obj = version_compare(PHP_VERSION, '5.4.0') >= 0 ? $ref->newInstanceWithoutConstructor() : unserialize('O:' . strlen($className) . ':"' . $className . '":0:{}');
		$this->objectMapping[$this->objectMappingIndex ++] = $obj;
		foreach ($value as $property => $propertyValue) {
			try {
				$propRef = $ref->getProperty($property);
				$propRef->setAccessible(true);
				$propRef->setValue($obj, $this->unserializeData($propertyValue));
			} catch (ReflectionException $e) {
				$obj->$property = $this->unserializeData($propertyValue);
			}
		}
		if (method_exists($obj, '__wakeup')) {
			$obj->__wakeup();
		}
		return $obj;
	}

	/**
	 * Reset variables
	 *
	 * @return void
	 */
	protected function reset()
	{
		$this->objectStorage = new SplObjectStorage();
		$this->objectMapping = array();
		$this->objectMappingIndex = 0;
	}
}
