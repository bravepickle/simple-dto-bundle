<?php

namespace Mell\Bundle\SimpleDtoBundle\Controller;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Mell\Bundle\SimpleDtoBundle\Model\Dto;
use Mell\Bundle\SimpleDtoBundle\Model\DtoInterface;
use Mell\Bundle\SimpleDtoBundle\Event\ApiEvent;
use Mell\Bundle\SimpleDtoBundle\Services\Dto\DtoManager;
use Mell\Bundle\SimpleDtoBundle\Services\RequestManager\RequestManager;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Debug\TraceableEventDispatcher;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

abstract class AbstractController extends Controller
{
    const FORMAT_JSON = 'json';

    const LIST_LIMIT_DEFAULT = 100;
    const LIST_LIMIT_MAX = 1000;

    /** @return string */
    abstract public function getDtoType();

    /** @return string */
    abstract public function getEntityAlias();

    /** @return array */
    abstract public function getAllowedExpands();

    /**
     * @param Request $request
     * @param $entity
     * @param string|null $dtoGroup
     * @return Response
     */
    protected function createResource(Request $request, $entity, $dtoGroup = null)
    {
        if (!$data = json_decode($request->getContent(), true)) {
            throw new BadRequestHttpException('Missing json data');
        }

        $dto = new Dto($this->getDtoType(), null, $dtoGroup ?: DtoInterface::DTO_GROUP_CREATE, $data);
        $entity = $this->getDtoManager()->createEntityFromDto(
            $entity,
            $dto,
            $this->getDtoType(),
            $dto->getGroup()
        );

        $event = new ApiEvent($entity, ApiEvent::ACTION_CREATE);
        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_PRE_VALIDATE, $event);

        $errors = $this->get('validator')->validate($entity);
        if ($errors->count()) {
            return $this->serializeResponse($errors);
        }

        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_PRE_PERSIST, $event);
        $this->getEntityManager()->persist($entity);

        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_PRE_FLUSH, $event);
        $this->getEntityManager()->flush();

        $event = new ApiEvent($entity, ApiEvent::ACTION_CREATE);
        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_POST_FLUSH, $event);

        return $this->serializeResponse(
            $this->getDtoManager()->createDto(
                $entity,
                $this->getDtoType(),
                $dtoGroup ?: DtoInterface::DTO_GROUP_READ,
                $this->get('simple_dto.request_manager')->getFields()
            )
        );
    }

    /**
     * @param Request $request
     * @param $entity
     * @param string|null $dtoGroup
     * @return Response
     */
    protected function updateResource(Request $request, $entity, $dtoGroup = null)
    {
        if (!$data = json_decode($request->getContent(), true)) {
            throw new BadRequestHttpException('Missing json data');
        }

        $dto = new Dto($this->getDtoType(), $entity, $dtoGroup ?: DtoInterface::DTO_GROUP_UPDATE, $data);
        $entity = $this->getDtoManager()->createEntityFromDto(
            $entity,
            $dto,
            $this->getDtoType(),
            $dto->getGroup()
        );

        $event = new ApiEvent($entity, ApiEvent::ACTION_UPDATE);
        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_PRE_VALIDATE, $event);

        $errors = $this->get('validator')->validate($entity);
        if ($errors->count()) {
            return $this->serializeResponse($errors);
        }

        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_PRE_FLUSH, $event);
        $this->getEntityManager()->flush();
        $event = new ApiEvent($entity, ApiEvent::ACTION_UPDATE);
        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_POST_FLUSH, $event);

        return $this->readResource($entity);
    }

    /**
     * @param $entity
     * @param string|null $dtoGroup
     * @return Response
     */
    protected function readResource($entity, $dtoGroup = null)
    {
        $event = new ApiEvent($entity, ApiEvent::ACTION_READ);
        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_POST_READ, $event);

        return $this->serializeResponse(
            $this->getDtoManager()->createDto(
                $entity,
                $this->getDtoType(),
                $dtoGroup ?: DtoInterface::DTO_GROUP_READ,
                $this->get('simple_dto.request_manager')->getFields()
            )
        );
    }

    /**
     * @param $entity
     * @return Response
     */
    protected function deleteResource($entity)
    {
        $this->getEntityManager()->remove($entity);

        $event = new ApiEvent($entity, ApiEvent::ACTION_DELETE);
        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_PRE_FLUSH, $event);

        $this->getEntityManager()->flush();
        $event = new ApiEvent($entity, ApiEvent::ACTION_DELETE);
        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_POST_FLUSH, $event);

        return new Response('', Response::HTTP_NO_CONTENT, ['Content-Type' => 'application/json']);
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param string $dtoGroup
     * @return Response
     */
    protected function listResources(QueryBuilder $queryBuilder, $dtoGroup = null)
    {
        $event = new ApiEvent($queryBuilder, ApiEvent::ACTION_LIST);
        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_PRE_COLLECTION_LOAD, $event);

        $this->processLimit($queryBuilder);
        $this->processOffset($queryBuilder);

        $collection = $queryBuilder->getQuery()->getResult();

        $event = new ApiEvent($collection, ApiEvent::ACTION_LIST);
        $this->getEventDispatcher()->dispatch(ApiEvent::EVENT_POST_COLLECTION_LOAD, $event);

        return $this->serializeResponse(
            $this->getDtoManager()->createDtoCollection(
                $collection,
                $this->getDtoType(),
                $dtoGroup ?: DtoInterface::DTO_GROUP_LIST,
                $this->get('simple_dto.request_manager')->getFields()
            )
        );
    }

    /**
     * @return EntityManager
     */
    protected function getEntityManager()
    {
        return $this->get('doctrine.orm.entity_manager');
    }

    /**
     * @param string $alias
     * @return QueryBuilder
     */
    protected function getQueryBuilder($alias = 't')
    {
        return $this->getEntityManager()->getRepository($this->getEntityAlias())->createQueryBuilder($alias);
    }

    /**
     * @param DtoInterface $data
     * @param int $statusCode
     * @param string $format
     * @return Response
     */
    protected function serializeResponse($data, $statusCode = Response::HTTP_OK, $format = self::FORMAT_JSON)
    {
        if ($data instanceof ConstraintViolationListInterface) {
            return $this->handleValidationError($data);
        }

        return new Response(
            $this->get('serializer')->serialize($data, $format, []),
            $statusCode,
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * @param ConstraintViolationListInterface $data
     * @return JsonResponse
     */
    protected function handleValidationError(ConstraintViolationListInterface $data)
    {
        $errors = [];
        /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
        foreach ($data as $violation) {
            if ($violation->getPropertyPath()) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            } else {
                $errors['_error'] = $violation->getMessage();
            }
        }

        return new JsonResponse($errors, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param QueryBuilder $queryBuilder
     */
    protected function processLimit(QueryBuilder $queryBuilder)
    {
        $limit = $this->getRequestManager()->getLimit() ? : static::LIST_LIMIT_DEFAULT;
        $queryBuilder->setMaxResults(min($limit, static::LIST_LIMIT_MAX));
    }

    /**
     * @param QueryBuilder $queryBuilder
     */
    protected function processOffset(QueryBuilder $queryBuilder)
    {
        $offset = $this->getRequestManager()->getOffset();
        if (!empty($offset)) {
            $queryBuilder->setFirstResult($offset);
        }
    }

    /**
     * @return DtoManager
     */
    protected function getDtoManager()
    {
        return $this->get('simple_dto.dto_manager');
    }

    /**
     * @return object|ContainerAwareEventDispatcher|TraceableEventDispatcher
     */
    protected function getEventDispatcher()
    {
        return $this->get('event_dispatcher');
    }

    /**
     * @return RequestManager|object
     */
    protected function getRequestManager()
    {
        return $this->get('simple_dto.request_manager');
    }
}
