<?php

namespace Youshido\GraphQLBundle\Execution;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Kernel;
use Youshido\GraphQL\Exception\ResolveException;
use Youshido\GraphQL\Execution\Context\ExecutionContextInterface;
use Youshido\GraphQL\Execution\Processor as BaseProcessor;
use Youshido\GraphQL\Execution\ResolveInfo;
use Youshido\GraphQL\Field\AbstractField;
use Youshido\GraphQL\Field\Field;
use Youshido\GraphQL\Field\FieldInterface;
use Youshido\GraphQL\Parser\Ast\Field as AstField;
use Youshido\GraphQL\Parser\Ast\Interfaces\FieldInterface as AstFieldInterface;
use Youshido\GraphQL\Parser\Ast\Query;
use Youshido\GraphQL\Parser\Ast\Query as AstQuery;
use Youshido\GraphQL\Type\TypeService;
use Youshido\GraphQLBundle\Event\ResolveEvent;
use Youshido\GraphQLBundle\Execution\Container\SymfonyContainer;
use Youshido\GraphQLBundle\Security\Manager\SecurityManagerInterface;

class Processor extends BaseProcessor
{

    /** @var  LoggerInterface */
    private $logger;

    /** @var  SecurityManagerInterface */
    private $securityManager;

    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    /**
     * Constructor.
     *
     * @param ExecutionContextInterface $executionContext
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(ExecutionContextInterface $executionContext, EventDispatcherInterface $eventDispatcher)
    {
        $this->executionContext = $executionContext;
        $this->eventDispatcher  = $eventDispatcher;

        parent::__construct($executionContext->getSchema());
    }

    /**
     * @param SecurityManagerInterface $securityManger
     *
     * @return Processor
     */
    public function setSecurityManager(SecurityManagerInterface $securityManger)
    {
        $this->securityManager = $securityManger;

        return $this;
    }

    public function processPayload($payload, $variables = [], $reducers = [])
    {
        if ($this->logger) {
            $this->logger->debug(sprintf('GraphQL query: %s', $payload), (array)$variables);
        }

        parent::processPayload($payload, $variables);
    }

    public function setLogger($logger = null)
    {
        $this->logger = $logger;
    }

    protected function resolveQuery(Query $query)
    {
        $this->assertClientHasOperationAccess($query);

        return parent::resolveQuery($query);
    }

    private function assertClientHasOperationAccess(Query $query)
    {
        if ($this->securityManager->isSecurityEnabledFor(SecurityManagerInterface::RESOLVE_ROOT_OPERATION_ATTRIBUTE)
            && !$this->securityManager->isGrantedToOperationResolve($query)
        ) {
            throw $this->securityManager->createNewOperationAccessDeniedException($query);
        }
    }

    protected function doResolve(FieldInterface $field, AstFieldInterface $ast, $parentValue = null)
    {
        /** @var AstQuery|AstField $ast */
        $arguments = $this->parseArgumentsValues($field, $ast);
        $astFields = $ast instanceof AstQuery ? $ast->getFields() : [];

        $event = new ResolveEvent($field, $astFields);
        $this->dispatchResolveEvent($event, 'graphql.pre_resolve');

        $resolveInfo = $this->createResolveInfo($field, $astFields);
        $this->assertClientHasFieldAccess($resolveInfo);

        if ($field instanceof ContainerAwareInterface) {
            $container = $this->executionContext->getContainer();

            assert($container instanceof SymfonyContainer);

            $field->setContainer($container->getSymfonyContainer());
        }

        if (($field instanceof AbstractField) && ($resolveFunc = $field->getConfig()->getResolveFunction())) {
            if ($this->isServiceReference($resolveFunc)) {
                $service = substr($resolveFunc[0], 1);
                $method  = $resolveFunc[1];
                if (!$this->executionContext->getContainer()->has($service)) {
                    throw new ResolveException(
                        sprintf('Resolve service "%s" not found for field "%s"', $service, $field->getName())
                    );
                }

                $serviceInstance = $this->executionContext->getContainer()->get($service);

                if (!method_exists($serviceInstance, $method)) {
                    throw new ResolveException(
                        sprintf(
                            'Resolve method "%s" not found in "%s" service for field "%s"',
                            $method,
                            $service,
                            $field->getName()
                        )
                    );
                }

                $result = $serviceInstance->$method($parentValue, $arguments, $resolveInfo);
            } else {
                $result = $resolveFunc($parentValue, $arguments, $resolveInfo);
            }
        } elseif ($field instanceof Field) {
            $result = TypeService::getPropertyValue($parentValue, $field->getName());
        } else {
            $result = $field->resolve($parentValue, $arguments, $resolveInfo);
        }

        $event = new ResolveEvent($field, $astFields, $result);
        $this->dispatchResolveEvent($event, 'graphql.post_resolve');

        return $event->getResolvedValue();
    }

    private function dispatchResolveEvent(ResolveEvent $event, $name)
    {
        $major = Kernel::MAJOR_VERSION;
        $minor = Kernel::MINOR_VERSION;

        $this->eventDispatcher->dispatch($event, $name);
    }

    private function assertClientHasFieldAccess(ResolveInfo $resolveInfo)
    {
        if ($this->securityManager->isSecurityEnabledFor(SecurityManagerInterface::RESOLVE_FIELD_ATTRIBUTE)
            && !$this->securityManager->isGrantedToFieldResolve($resolveInfo)
        ) {
            throw $this->securityManager->createNewFieldAccessDeniedException($resolveInfo);
        }
    }

    private function isServiceReference($resolveFunc)
    {
        return is_array($resolveFunc) && count($resolveFunc) == 2 && strpos($resolveFunc[0], '@') === 0;
    }
}
