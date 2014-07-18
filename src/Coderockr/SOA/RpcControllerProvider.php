<?php

namespace Coderockr\SOA;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;

class RpcControllerProvider implements ControllerProviderInterface
{
    const AUTHORIZATION_HEADER = 'authorization';

    private $useCache = false;
    private $cache;
    private $serviceNamespace;
    private $em;
    private $authenticationService = null;
    private $authorizationService = null;
    private $noAuthCalls = array();

    public function setCache($cache)
    {
        $this->useCache = true;
        $this->cache = $cache;
    }

    public function setEntityManager($em)
    {
        $this->em = $em;
    }

    public function getNoAuthCalls()
    {
        return $this->noAuthCalls;
    }
    
	public function setServiceNamespace($serviceNamespace)
	{
		$this->serviceNamespace = $serviceNamespace;
	}

	protected function serialize($data, $type)
	{
		$serializer = SerializerBuilder::create()->setPropertyNamingStrategy(new IdenticalPropertyNamingStrategy())->build();
		return $serializer->serialize($data, $type);
	}

    public function getAuthorizationService()
    {
        return $this->authorizationService;
    }
     
    public function setAuthorizationService($authorizationService)
    {
        return $this->authorizationService = $authorizationService;
    }

    public function getAuthenticationService()
    {
        return $this->authenticationService;
    }
     
    public function setAuthenticationService($authenticationService, $noAuthCalls = array())
    {
        $this->noAuthCalls = $noAuthCalls;
        return $this->authenticationService = $authenticationService;
    }

	public function connect(Application $app)
    {
    	$this->setEntityManager($app['orm.em']);
        $controllers = $app['controllers_factory'];

        $controllers->get('/', function (Application $app) {
            return 'TODO: documentation';
        });
        
        $controllers->post('/{service}/{method}', function ($service, $method, Request $request) use ($app)
        {
            $service = $this->serviceNamespace . '\\' . ucfirst($service);

            if (!class_exists($service)) {
                return new Response('Invalid service.', 400, array('Content-Type' => 'text/json'));
            }
            
            $class = new $service();
            $class->setEm($this->em);

            if (!$parameters = $request->get('parameters')) 
                $parameters = array();
            
            if (method_exists($class, $method)) {
                $result = $class->$method($parameters);
            }
            else {
                $result = array('status' => 'error', 'data' => 'Method not found', 'statusCode' => 400);
            }
            
            switch ($result['status']) {
                case 'success':
                    
                    return new Response($this->serialize($result['data'], 'json'), 
                                        isset($result['statusCode']) ? $result['statusCode'] : 200, 
                                        array('Content-Type' => 'text/json'));

                    break;
                case 'error':
                    
                    return new Response('Error executing service - ' . $this->serialize($result['data'], 'json'), 
                                        isset($result['statusCode']) ? $result['statusCode'] : 400, 
                                        array('Content-Type' => 'text/json'));
                    
                    break;
            }

        })->value('method', 'execute');

		$controllers->match('/{service}', function ($service, Request $request) use ($app) 
        {
            return new Response('', 200, array(
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE',
                'Access-Control-Allow-Headers' => 'Authorization'
            ));
        })->method('OPTIONS')->value('service', null);

        $controllers->before(function (Request $request) use ($app) {
            
            if ($request->getMethod() == 'OPTIONS') {
                return;
            }

            $resource = $request->get('_route_params');
            $route = $resource['service'] .'/'.$resource['method'];
            
            if (in_array($route, $this->getNoAuthCalls())) {
                return;
            }

            $authService = $this->getAuthenticationService();
            if ($authService) {
                if(!$request->headers->has($this::AUTHORIZATION_HEADER)) {
                    return new Response('Unauthorized', 401);
                }

                $token = $request->headers->get($this::AUTHORIZATION_HEADER);
                $authService->setEm($this->em);

                if (!$$authService->authenticate($token)) {
                    return new Response('Unauthorized', 401);    
                }

                $authorizationService = $this->getAuthorizationService();
                if ($authorizationService) {
                    
                    $authorizationService->setEm($this->em);
                    if (!$authorizationService->isAuthorized($token, $resource['entity'])) {
                        return new Response('Unauthorized', 401);    
                    }
                }
            }
        });

        return $controllers;
    }
}