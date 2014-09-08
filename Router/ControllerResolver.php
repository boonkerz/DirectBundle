<?php
namespace Ext\DirectBundle\Router;

use Ext\DirectBundle\Request\Call;
use Symfony\Bundle\FrameworkBundle\Controller\ControllerResolver as BaseControllerResolver;
use Symfony\Bundle\FrameworkBundle\Controller\ControllerNameParser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use AppKernel;

/**
 * @author Semyon Velichko <semyon@velichko.net>
 */
class ControllerResolver extends BaseControllerResolver
{

    /**
     * @var RouteCollection
     */
    private $collection;

    /**
     * @var Call
     */
    private $currentCall;

    /**
     * @var Rule
     */
    private $currentRule;

    /**
     * @param ContainerInterface   $container
     * @param ControllerNameParser $parser
     * @param RouteCollection      $collection
     * @param LoggerInterface      $logger
     */
    public function __construct(
        ContainerInterface $container,
        ControllerNameParser $parser,
        RouteCollection $collection,
        LoggerInterface $logger = null)
    {
        $this->collection = $collection;

        $this->kernel = $container->get('kernel');
        parent::__construct($container, $parser, $logger);
    }

    /**
     * @return \Ext\DirectBundle\Router\RouteCollection
     */
    private function getRouteCollection()
    {
        return $this->collection;
    }

    /**
     * @return ContainerInterface
     */
    private function getContainer()
    {
        return $this->container;
    }

    /**
     * @return AppKernel
     */
    private function getKernel()
    {
        return $this->kernel;
    }

    /**
     * @param \Ext\DirectBundle\Request\Call $currentCall
     */
    private function setCurrentCall($currentCall)
    {
        $this->currentCall = $currentCall;
    }

    /**
     * @return \Ext\DirectBundle\Request\Call
     */
    private function getCurrentCall()
    {
        return $this->currentCall;
    }

    /**
     * @param \Ext\DirectBundle\Router\Rule $currentRule
     */
    private function setCurrentRule($currentRule)
    {
        $this->currentRule = $currentRule;
    }

    /**
     * @return \Ext\DirectBundle\Router\Rule
     */
    public function getCurrentRule()
    {
        return $this->currentRule;
    }

    /**
     * @param string $controller
     *
     * @return $rule
     * @throws \BadMethodCallException
     */
    private function findRuleByController($controller)
    {
        foreach ($this->getRouteCollection() as $key => $rule) {
            if ($rule->getController() === $controller) {
                $this->setCurrentRule($rule);

                return $rule;
            }

        }
        throw new \BadMethodCallException(sprintf('%1$s does not configured, check config.yml', $controller));
    }

    /**
     * @param Call $call
     *
     * @return array
     */
    private function createCallableFromServiceCall(Call $call)
    {
        $fullPath = sprintf('%1$s:%2$s', $call->getAction(), $call->getMethod());
        $this->findRuleByController($fullPath);

        $controller = $this->getContainer()->get($call->getAction());
        $method = $call->getMethod();

        return $this->createCallable($controller, $method);
    }

    /**
     * @param Call $call
     *
     * @return array
     */
    private function createCallableFromControllerMethodCall(Call $call)
    {
        $explodeResult = explode('_', $call->getAction());

        list($bundle, $controller) = $explodeResult;
        $fullPath = sprintf('%1$sBundle:%2$s:%3$s', $bundle, $controller, $call->getMethod());

        $this->findRuleByController($fullPath);

        $bundle = $this->getKernel()->getBundle(sprintf('%sBundle', $bundle));

        $controller = sprintf('%s\\Controller\\%sController::%sAction', $bundle->getNamespace(), $controller, $call->getMethod());

        if (is_array($controller) || (is_object($controller) && method_exists($controller, '__invoke'))) {
            return $controller;
        }

        if (false === strpos($controller, ':') && method_exists($controller, '__invoke')) {
            return new $controller;
        }

        list($controller, $method) = $this->createController($controller);

        return $this->createCallable($controller, $method);
    }

    /**
     * @param Call $call
     *
     * @return array
     */
    public function getControllerFromCall(Call $call)
    {
        $this->setCurrentCall($call);

        $explodeResult = explode('_', $call->getAction());

        if (count($explodeResult) <> 2) {
            return $this->createCallableFromServiceCall($call);
        }

        return $this->createCallableFromControllerMethodCall($call);
    }

    /**
     * @param mixed  $controller
     * @param string $method
     *
     * @return array
     * @throws \BadMethodCallException
     */
    private function createCallable($controller, $method)
    {
        if (!method_exists($controller, $method)) {
            throw new \BadMethodCallException(sprintf('Method "%s::%s" does not exist.', get_class($controller), $method));
        }

        return array($controller, $method);
    }

    /**
     * @param HttpRequest $request
     * @param mixed       $controller
     * @param array       $parameters
     *
     * @return array
     * @throws \LogicException
     * @throws \RuntimeException
     */
    protected function doGetArguments(HttpRequest $request, $controller, array $parameters)
    {
        if (!$this->getCurrentCall()) {
            throw new \LogicException('$this->currentCall is null, run setCurrentCall(Call $call) or getControllerFromCall(Call $call) before use getArguments()');
        }

        $attributes = $this->getCurrentCall()->getData();
        $arguments = array();
        foreach ($parameters as $param) {
            if (is_string($attributes) || is_int($attributes)){
                $arguments['data'] = $attributes;
            }elseif (in_array($param->getName(), array('_data', '_list'))) {
                $arguments[] = $attributes;
                if ('_list' === $param->getName() && !isset($attributes[0])) {
                    array_pop($arguments);
                    $arguments[] = array($attributes);
                }
            } elseif (array_key_exists($param->getName(), $attributes)) {
                $arguments[] = $attributes[$param->getName()];
            } elseif ($param->getClass() && $param->getClass()->isInstance($request)) {
                $arguments[] = $request;
            } elseif ($param->isDefaultValueAvailable()) {
                $arguments[] = $param->getDefaultValue();
            } else {
                if (is_array($controller)) {
                    $repr = sprintf('%s::%s()', get_class($controller[0]), $controller[1]);
                } elseif (is_object($controller)) {
                    $repr = get_class($controller);
                } else {
                    $repr = $controller;
                }

                throw new \RuntimeException(sprintf('Controller "%s" requires that you provide a value for the "$%s" argument (because there is no default value or because there is a non optional argument after this one).', $repr, $param->getName()));
            }
        }

        return $arguments;
    }

    /**
     * @param \ReflectionMethod $method
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public function genAction(\ReflectionMethod $method)
    {
        if (!preg_match('/^(.+)\\\(.+Bundle)\\\Controller\\\(.+)Controller$/', $method->class, $cMatch)) {
            throw new \InvalidArgumentException();
        }

        unset($cMatch[0]);

        if (!preg_match('/^(.+)Action$/', $method->name, $mMatch)) {
            throw new \InvalidArgumentException();
        }

        $cMatch[4] = $cMatch[3];
        $cMatch[3] = ':';

        return implode('', $cMatch) . ':' . $mMatch[1];
    }
}
