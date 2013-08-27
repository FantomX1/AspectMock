<?php
namespace AspectMock\Core;
use Go\Aop\Aspect;
use Go\Aop\Intercept\FunctionInvocation;
use Go\Aop\Intercept\MethodInvocation;
use Go\Lang\Annotation\Around;

class Mocker implements Aspect {

    protected $classMap = [];
    protected $objectMap = [];
    protected $funcMap = [];

    /**
     * @Around("execution(**\*(*))")
     */
    public function mockFunction(FunctionInvocation $invocation)
    {
        $name = $invocation->getFunction();
        if (in_array($name, $this->funcMap)) {
            $func = $this->turnToClosure($this->funcMap[$name]);
            return $func();
        }
        return $invocation->proceed();
    }

    /**
     * @Around("within(**)")
     */
    public function fakeMethodsAndRegisterCalls(MethodInvocation $invocation)
    {
        $obj = $invocation->getThis();
        $method = $invocation->getMethod()->name;
        
        $result = $this->invokeFakedMethods($invocation);

        if (is_object($obj)) {
            Registry::registerInstanceCall($obj, $method, $invocation->getArguments(), $result);
            $class = get_class($obj);
        } else {
            $class = $obj;
        }
        Registry::registerClassCall($class, $method, $invocation->getArguments(), $result);
        return $result;
    }

    protected function invokeFakedMethods(MethodInvocation $invocation)
    {
        $obj = $invocation->getThis();
        $method = $invocation->getMethod()->name;

        if (is_object($obj)) {
            // instance method
            $params = $this->getObjectMethodStubParams($obj, $method);
            if ($params !== false) return $this->stub($invocation, $params);

            // class method
            $params = $this->getClassMethodStubParams(get_class($obj), $method);
            if ($params !== false) return $this->stub($invocation, $params);

            // inheritance
            $calledClass = $this->getRealClassName($invocation->getMethod()->getDeclaringClass());
            $params = $this->getClassMethodStubParams($calledClass, $method);
            if ($params !== false) return $this->stub($invocation, $params);

            // magic methods
            if ($method == '__call') {
                $method = reset($invocation->getArguments());

                $params = $this->getObjectMethodStubParams($obj, $method);
                if ($params !== false) return $this->stubMagicMethod($invocation, $params);

                // magic class method
                $params = $this->getClassMethodStubParams(get_class($obj), $method);
                if ($params !== false) return $this->stubMagicMethod($invocation, $params);

                // inheritance
                $calledClass = $this->getRealClassName($invocation->getMethod()->getDeclaringClass());
                $params = $this->getClassMethodStubParams($calledClass, $method);
                if ($params !== false) return $this->stubMagicMethod($invocation, $params);
            }
        } else {
            // static method
            $params = $this->getClassMethodStubParams($obj, $method);
            if ($params !== false) return $this->stub($invocation, $params);

            // magic static method (facade)
            if ($method == '__callStatic') {
                $method = reset($invocation->getArguments());

                $params = $this->getClassMethodStubParams($obj, $method);
                if ($params !== false) return $this->stubMagicMethod($invocation, $params);

                // inheritance
                $calledClass = $this->getRealClassName($invocation->getMethod()->getDeclaringClass());
                $params = $this->getClassMethodStubParams($calledClass, $method);
                if ($params !== false) return $this->stubMagicMethod($invocation, $params);
            }

        }
        return $invocation->proceed();
    }

    protected function getObjectMethodStubParams($obj, $method_name)
    {
        $oid = spl_object_hash($obj);
        if (!isset($this->objectMap[$oid])) return false;
        $params = $this->objectMap[$oid];
        if (!array_key_exists($method_name,$params)) return false;
        return $params;
    }

    protected function getClassMethodStubParams($class_name, $method_name)
    {
        if (!isset($this->classMap[$class_name])) return false;
        $params = $this->classMap[$class_name];
        if (!array_key_exists($method_name,$params)) return false;
        return $params;
    }
    
    protected function stub(MethodInvocation $invocation, $params)
    {
        $name = $invocation->getMethod()->name;

        $replacedMethod = $params[$name];

        $replacedMethod = $this->turnToClosure($replacedMethod);

        if ($invocation->getMethod()->isStatic()) {
            \Closure::bind($replacedMethod, null, $invocation->getThis());
        } else {
            $replacedMethod = $replacedMethod->bindTo($invocation->getThis(), get_class($invocation->getThis()));
        }
        return call_user_func_array($replacedMethod, $invocation->getArguments());
    }

    protected function stubMagicMethod(MethodInvocation $invocation, $params)
    {
        $args = $invocation->getArguments();
        $name = array_shift($args);

        $replacedMethod = $params[$name];
        $replacedMethod = $this->turnToClosure($replacedMethod);

        if ($invocation->getMethod()->isStatic()) {
            \Closure::bind($replacedMethod, null, $invocation->getThis());
        } else {
            $replacedMethod = $replacedMethod->bindTo($invocation->getThis(), get_class($invocation->getThis()));
        }
        return call_user_func_array($replacedMethod, $args);
    }


    protected function turnToClosure($returnValue)
    {
        if ($returnValue instanceof \Closure) return $returnValue;
        return function() use ($returnValue) {
            return $returnValue;
        };
    }

    public function registerClass($class, $params = array())
    {
        $class = ltrim($class,'\\');
        if (isset($this->classMap[$class])) {
            $params = array_merge($this->classMap[$class], $params);
        }
        $this->classMap[$class] = $params;
    }

    public function registerFunc($func, $closure)
    {
        $this->funcMap[$func] = $closure;
    }

    public function registerObject($object, $params = array())
    {
        $hash = spl_object_hash($object);
        if (isset($this->objectMap[$hash])) {
            $params = array_merge($this->objectMap[$hash], $params);
        }
        $this->objectMap[$hash] = $params;
    }

    public function clean($objectOrClass = null)
    {
        if (!$objectOrClass) {
            $this->classMap = [];
            $this->objectMap = [];
            $this->funcMap = [];
        } elseif (is_object($objectOrClass)) {
            unset($this->objectMap[spl_object_hash($objectOrClass)]);
        } else {
            unset($this->classMap[$objectOrClass]);
        }
    }

    private function getRealClassName($class)
    {
        return str_replace('__AopProxied','', $class->name);
    }
}
