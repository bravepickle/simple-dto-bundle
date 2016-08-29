<?php

namespace Mell\Bundle\SimpleDtoBundle\Services\Dto;

use Mell\Bundle\SimpleDtoBundle\Event\ApiEvent;
use Mell\Bundle\SimpleDtoBundle\Exceptions\DtoException;
use Mell\Bundle\SimpleDtoBundle\Helpers\DtoHelper;
use Mell\Bundle\SimpleDtoBundle\Model\Dto;
use Mell\Bundle\SimpleDtoBundle\Model\DtoCollection;
use Mell\Bundle\SimpleDtoBundle\Model\DtoInterface;
use Mell\Bundle\SimpleDtoBundle\Model\DtoManagerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Class DtoManager
 * @package Mell\Bundle\SimpleDtoBundle\Services\Dto
 */
class DtoManager implements DtoManagerInterface
{
    /** @var DtoValidator */
    protected $dtoValidator;
    /** @var DtoHelper */
    protected $dtoHelper;
    /** @var DtoManagerConfigurator */
    protected $configurator;
    /** @var EventDispatcherInterface */
    protected $eventDispatcher;

    /**
     * DtoManager constructor.
     * @param DtoValidator $dtoValidator
     * @param DtoHelper $dtoHelper
     * @param DtoManagerConfigurator $configurator
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        DtoValidator $dtoValidator,
        DtoHelper $dtoHelper,
        DtoManagerConfigurator $configurator,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->dtoValidator = $dtoValidator;
        $this->dtoHelper = $dtoHelper;
        $this->configurator = $configurator;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * Convert entity to dto
     *
     * @param object $entity
     * @param string $dtoType
     * @param string $group
     * @param array $fields
     * @param bool $trowEvents
     * @return DtoInterface
     */
    public function createDto($entity, $dtoType, $group, array $fields, $trowEvents = true)
    {
        $dtoConfig = $this->dtoHelper->getDtoConfig();
        $this->validateDtoConfig($dtoConfig, $dtoType, $entity);

        $dto = new Dto($dtoType, $entity, $group, []);

        if ($trowEvents) {
            $event = new ApiEvent($dto, ApiEvent::ACTION_CREATE_DTO);
            $this->eventDispatcher->dispatch(ApiEvent::EVENT_PRE_DTO_ENCODE, $event);
        }

        $fieldsConfig = $dtoConfig[$dtoType]['fields'];
        /** @var array $options */
        foreach ($fieldsConfig as $field => $options) {
            // field was not required (@see dtoManager::getRequiredFields)
            if (!empty($fields) && !in_array($field, $fields)) {
                continue;
            }
            // field is not allowed for specified group
            if (!empty($options['groups']) && !in_array($group, $options['groups'])) {
                continue;
            }

            $getter = isset($options['getter']) ? $options['getter'] : $this->dtoHelper->getFieldGetter($field);
            $value = call_user_func([$entity, $getter]);
            $dto->append([$field => $this->dtoHelper->castValueType($options['type'], $value)]);
        }

        if ($trowEvents) {
            $event = new ApiEvent($dto, ApiEvent::ACTION_CREATE_DTO);
            $this->eventDispatcher->dispatch(ApiEvent::EVENT_POST_DTO_ENCODE, $event);
        }

        return $dto;
    }

    /**
     * @param array $collection
     * @param $dtoType
     * @param $group
     * @param array $fields
     * @param array $expands
     * @param string $collectionKey
     * @return DtoInterface
     */
    public function createDtoCollection(
        $collection,
        $dtoType,
        $group,
        array $fields = [],
        array $expands = [],
        $collectionKey = null
    ) {
        $dtoCollection = new DtoCollection($dtoType, $collection, $this->processCollectionKey($collectionKey), $group);
        $event = new ApiEvent($dtoCollection, ApiEvent::ACTION_CREATE_DTO_COLLECTION);
        $this->eventDispatcher->dispatch(ApiEvent::EVENT_PRE_DTO_COLLECTION_ENCODE, $event);

        foreach ($collection as $item) {
            $dtoCollection->append($this->createDto($item, $dtoType, $group, $fields, false));
        }

        $event = new ApiEvent($dtoCollection, ApiEvent::ACTION_CREATE_DTO_COLLECTION);
        $this->eventDispatcher->dispatch(ApiEvent::EVENT_POST_DTO_COLLECTION_ENCODE, $event);

        return $dtoCollection;
    }

    /**
     * @param $entity
     * @param DtoInterface $dto
     * @param string $dtoType
     * @param string|null $group
     * @return mixed
     */
    public function createEntityFromDto($entity, DtoInterface $dto, $dtoType, $group)
    {
        $dtoConfig = $this->dtoHelper->getDtoConfig();
        $this->validateDto($dto, $dtoConfig, $dtoType);

        $fieldsConfig = $dtoConfig[$dtoType]['fields'];
        foreach ($dto->getRawData() as $property => $value) {
            if (!isset($fieldsConfig[$property])) {
                throw new BadRequestHttpException(sprintf('%s: field "%s" is not defined', $dtoType, $property));
            }
            if (!empty($fieldsConfig[$property]['readonly'])) {
                continue;
            }
            if (isset($fieldsConfig[$property]['groups']) && !in_array($group, $fieldsConfig[$property]['groups'])) {
                continue;
            }
            $value = $this->castValueType($fieldsConfig[$property]['type'], $value, false);
            $setter = isset($fieldsConfig[$property]['setter'])
                ? $fieldsConfig[$property]['setter']
                : $this->dtoHelper->getFieldSetter($property);

            call_user_func([$entity, $setter], $value);
        }

        return $entity;
    }

    /**
     * @param string $dtoType DtoConfig name (UserDto as example)
     * @return bool
     */
    public function hasConfig($dtoType)
    {
        return array_key_exists($dtoType, $this->dtoHelper->getDtoConfig());
    }

    /**
     * @param string $dtoType
     * @return mixed
     * @throws DtoException
     */
    public function getConfig($dtoType)
    {
        if ($this->hasConfig($dtoType)) {
            return $this->dtoHelper->getDtoConfig()[$dtoType];
        }

        throw new DtoException(sprintf('Dto config not found: %s', $dtoType));
    }

    /**
     * @param $entity
     * @param array $dtoData Predefined dto data
     * @param array $fields Required fields
     * @param array $config Fields configuration
     * @param string $group Dto group
     */
    protected function processFields($entity, array &$dtoData, array $fields, array $config, $group)
    {
        /** @var array $options */
        foreach ($config as $field => $options) {
            // field was not required (@see dtoManager::getRequiredFields)
            if (!empty($fields) && !in_array($field, $fields)) {
                continue;
            }
            // field is not allowed for specified group
            if (!empty($options['groups']) && !in_array($group, $options['groups'])) {
                continue;
            }

            $getter = isset($options['getter']) ? $options['getter'] : $this->dtoHelper->getFieldGetter($field);
            $value = call_user_func([$entity, $getter]);
            $dtoData[$field] = $this->castValueType($options['type'], $value);
        }
    }

    /**
     * @param $entity
     * @param array $dtoData Predefined dto data
     * @param array $expands Required expands
     * @param array $config Expands configuration
     * @param string $group Dto group
     */
    protected function processExpands($entity, array &$dtoData, array $expands, array $config, $group)
    {
        $this->validateExpands($config, $expands);
        foreach ($expands as $expand => $fields) {
            $expandConfig = $config[$expand];
            $expandGetter = !empty($expandConfig['getter'])
                ? $expandConfig['getter']
                : $this->dtoHelper->getFieldGetter($expand);
            if (!$expandObject = call_user_func([$entity, $expandGetter])) {
                continue;
            }
            if (is_array($expandObject) || $expandObject instanceof \ArrayAccess) {
                $dtoData['_expands'][$expand] = $this->createDtoCollection(
                    $expandObject,
                    $expandConfig['type'],
                    $group,
                    $fields,
                    [],
                    false // do not add collection key
                );
            } else {
                $dtoData['_expands'][$expand] = $this->createDto($expandObject, $expandConfig['type'], $group, $fields);
            }
        }
    }

    /**
     * @param array $dtoConfig
     * @param string $dtoType
     * @param $object
     */
    protected function validateDtoConfig($dtoConfig, $dtoType, $object)
    {
        $this->dtoValidator->validateDtoConfig($dtoConfig, $object, $dtoType);
    }

    /**
     * @param DtoInterface $dto
     * @param array $config
     * @param string $dtoType
     */
    protected function validateDto(DtoInterface $dto, $config, $dtoType)
    {
        $this->dtoValidator->validateDto($dto, $config, $dtoType);
    }

    /**
     * @param array $config
     * @param array $expands
     */
    protected function validateExpands(array $config, array $expands)
    {
        return $this->dtoValidator->validateExpands($config, $expands);
    }

    /**
     * @param string $type
     * @param mixed $value
     * @param bool $raw
     * @return mixed
     */
    protected function castValueType($type, $value, $raw = true)
    {
        switch ($type) {
            case DtoInterface::TYPE_INTEGER:
                $value = intval($value);
                break;
            case DtoInterface::TYPE_FLOAT:
                $value = floatval($value);
                break;
            case DtoInterface::TYPE_STRING:
                $value = (string)$value;
                break;
            case DtoInterface::TYPE_BOOLEAN:
                $value = boolval($value);
                break;
            case DtoInterface::TYPE_ARRAY:
                $value = (array)$value;
                break;
            case DtoInterface::TYPE_DATE:
                if (!$value instanceof \DateTime) {
                    $value = new \DateTime($value);
                }
                if ($raw) {
                    $value = $value->format($this->configurator->getFormatDate());
                }
                break;
            case DtoInterface::TYPE_DATE_TIME:
                if (!$value instanceof \DateTime) {
                    $value = new \DateTime($value);
                }
                if ($raw) {
                    $value = $value->format($this->configurator->getFormatDateTime());
                }
                break;
            default:
                $value = null;
        }

        return $value;
    }

    /**
     * @param $collectionKey
     * @return string
     */
    private function processCollectionKey($collectionKey)
    {
        return $collectionKey !== null ? $collectionKey : $this->configurator->getCollectionKey();
    }
}
