<?php

namespace Youshido\GraphQLBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Youshido\GraphQLBundle\Exception\UnableToInitializeSchemaServiceException;
use Youshido\GraphQLBundle\Execution\Processor;

class GraphQLController extends AbstractController
{
    private Processor $processor;

    public function __construct(Processor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * @Route("/graphql")
     *
     * @return JsonResponse
     * @throws \Exception
     *
     */
    public function defaultAction()
    {
        try {
            $this->initializeSchemaService();
        } catch (UnableToInitializeSchemaServiceException $e) {
            return new JsonResponse(
                [['message' => 'Schema class '.$this->getSchemaClass().' does not exist']],
                200,
                $this->getResponseHeaders()
            );
        }

        if ($this->get('request_stack')->getCurrentRequest()->getMethod() == 'OPTIONS') {
            return $this->createEmptyResponse();
        }

        [$queries, $isMultiQueryRequest] = $this->getPayload();

        $queryResponses = array_map(function ($queryData) {
            return $this->executeQuery($queryData['query'], $queryData['variables']);
        }, $queries);

        $response = new JsonResponse(
            $isMultiQueryRequest ? $queryResponses : $queryResponses[0],
            200,
            $this->getParameter('graphql.response.headers')
        );

        if ($this->getParameter('graphql.response.json_pretty')) {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT);
        }

        return $response;
    }

    /**
     * @throws \Exception
     */
    private function initializeSchemaService()
    {
//        $this->container->set('graphql.schema', $this->makeSchemaService());
    }

    private function getSchemaClass(): string
    {
        return (string)$this->getParameter('graphql.schema_class');
    }

    private function getResponseHeaders()
    {
        return $this->getParameter('graphql.response.headers');
    }

    private function createEmptyResponse()
    {
        return new JsonResponse([], 200, $this->getResponseHeaders());
    }

    /**
     * @return array
     *
     * @throws \Exception
     */
    private function getPayload()
    {
        $request             = $this->get('request_stack')->getCurrentRequest();
        $query               = $request->get('query', null);
        $variables           = $request->get('variables', []);
        $isMultiQueryRequest = false;
        $queries             = [];

        $variables = is_string($variables) ? json_decode($variables, true) ?: [] : [];

        $content = $request->getContent();
        if (!empty($content)) {
            if ($request->headers->has('Content-Type') && 'application/graphql' == $request->headers->get(
                    'Content-Type'
                )) {
                $queries[] = [
                    'query'     => $content,
                    'variables' => [],
                ];
            } else {
                $params = json_decode($content, true);

                if ($params) {
                    // check for a list of queries
                    if (isset($params[0]) === true) {
                        $isMultiQueryRequest = true;
                    } else {
                        $params = [$params];
                    }

                    foreach ($params as $queryParams) {
                        $query = isset($queryParams['query']) ? $queryParams['query'] : $query;

                        if (isset($queryParams['variables'])) {
                            if (is_string($queryParams['variables'])) {
                                $variables = json_decode($queryParams['variables'], true) ?: $variables;
                            } else {
                                $variables = $queryParams['variables'];
                            }

                            $variables = is_array($variables) ? $variables : [];
                        }

                        $queries[] = [
                            'query'     => $query,
                            'variables' => $variables,
                        ];
                    }
                }
            }
        } else {
            $queries[] = [
                'query'     => $query,
                'variables' => $variables,
            ];
        }

        return [$queries, $isMultiQueryRequest];
    }

    private function executeQuery($query, $variables)
    {
        $this->processor->processPayload($query, $variables);

        return $this->processor->getResponseData();
    }

    /**
     * @return object
     *
     * @throws \Exception
     */
    private function makeSchemaService()
    {
        if ($this->container->has($this->getSchemaService())) {
            return $this->container->get($this->getSchemaService());
        }

        $schemaClass = $this->getSchemaClass();
        if (!$schemaClass || !class_exists($schemaClass)) {
            throw new UnableToInitializeSchemaServiceException();
        }

        if ($this->container->has($schemaClass)) {
            return $this->container->get($schemaClass);
        }

        $schema = new $schemaClass();
        if ($schema instanceof ContainerAwareInterface) {
            $schema->setContainer($this->container);
        }

        return $schema;
    }

    private function getSchemaService(): string
    {
        $serviceName = (string)$this->getParameter('graphql.schema_service');

        if (substr($serviceName, 0, 1) === '@') {
            return substr($serviceName, 1, strlen($serviceName) - 1);
        }

        return $serviceName;
    }
}
