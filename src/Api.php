<?php

namespace LaravelApi;

use Calcinai\Strut\Definitions\Definitions;
use Calcinai\Strut\Definitions\Info;
use Calcinai\Strut\Definitions\Paths;
use Calcinai\Strut\Definitions\Tag;
use Calcinai\Strut\Swagger;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Http\Request;
use Illuminate\Routing\ResourceRegistrar;
use Illuminate\Routing\Router;
use Illuminate\Support\Traits\Macroable;
use LaravelApi\Endpoints\Parameters\PathParameter;
use LaravelApi\Endpoints\ResourceEndpoint;
use LaravelApi\Http\Controllers\AggregateController;

/**
 * @method Endpoints\Operation get( string $uri, \Closure | array | string $action )
 * @method Endpoints\Operation post( string $uri, \Closure | array | string $action )
 * @method Endpoints\Operation put( string $uri, \Closure | array | string $action )
 * @method Endpoints\Operation delete( string $uri, \Closure | array | string $action )
 * @method Endpoints\Operation patch( string $uri, \Closure | array | string $action )
 * @method Endpoints\Operation options( string $uri, \Closure | array | string $action )
 * @method group ( array $attributes, \Closure | string $routes )
 */
class Api implements \JsonSerializable
{
    use Auth\DefinesAuthorization;
    use Macroable
    {
        __call as macroCall;
    }

    /**
     * @var Container
     */
    protected Container $app;

    /**
     * @var Registrar|Router
     */
    protected $router;

    /**
     * @var Swagger
     */
    protected Swagger $swagger;

    /**
     * @var array|PathParameter[]
     */
    protected array $parameters = [];

    /**
     * @var array
     */
    protected array $passthru = [ 'group' ];

    /**
     * @var array
     */
    protected array $passthruVerbs = [ 'get', 'post', 'put', 'delete', 'patch', 'options' ];


    /**
     * @param Container $app
     * @param Registrar $router
     * @param Request $request
     */
    public function __construct(Container $app, Registrar $router, Request $request)
    {
        $this->app = $app;

        $this->router = $router;

        $this->swagger = Swagger::create()
            ->setInfo($this->buildInfo())
            ->setHost($request->getHttpHost())
            ->setBasePath('/' . config('api.prefix', 'api'))
            ->addScheme(config('api.scheme', $request->getScheme()))
            ->setConsumes([ 'application/json' ])
            ->setProduces([ 'application/json' ])
            ->setDefinitions(Definitions::create())
            ->setPaths(Paths::create());
    }


    /**
     * @return Swagger
     */
    public function swagger(): Swagger
    {
        return $this->swagger;
    }


    /**
     * @return Info
     */
    protected function buildInfo(): Info
    {
        return Info::create()
                   ->setTitle(config('api.title', config('app.name') . ' API'))
                   ->setDescription(config('api.description'))
                   ->setVersion(config('api.version', '1.0.0'));
    }


    /**
     * @return string
     */
    public function title(): string
    {
        return $this->swagger->getInfo()->getTitle();
    }


    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->swagger->jsonSerialize();
    }


    /**
     * @param string $name
     * @param string|null $description
     * @param \Closure|string $callback
     *
     * @return Tag
     */
    public function tag(string $name, string $description = null, $callback = null): Tag
    {
        $this->swagger->addTag($tag = Tag::create(compact('name', 'description')));

        if (! is_null($callback)) {
            $this->router->group([ 'tags' => $name ], $callback);
        }

        return $tag;
    }


    /**
     * @param array $tags
     */
    public function tags(array $tags)
    {
        foreach ($tags as $name => $description) {
            $this->tag($name, $description);
        }
    }


    /**
     * @param string $name
     *
     * @return Definition
     * @throws \Exception
     */
    public function definition(string $name): Definition
    {
        $definition = Definition::create()->setName($name);

        $this->swagger->getDefinitions()->set($name, $definition);

        return $definition;
    }


    /**
     * @param string $version
     * @param \Closure|string $routes
     */
    public function version(string $version, $routes)
    {
        $this->router->group([ 'prefix' => $version, 'tags' => $version ], $routes);
    }


    /**
     * @param string $name
     * @param string $controller
     * @param array $options
     *
     * @return ResourceEndpoint
     * @throws BindingResolutionException
     */
    public function resource(string $name, string $controller, array $options = []): ResourceEndpoint
    {
        $registrar = $this->app->make(ResourceRegistrar::class);

        $options = array_merge([ 'only' => [ 'index', 'show', 'store', 'update', 'destroy' ], ], $options);

        return ( new ResourceEndpoint($registrar, $name, $controller, $options) )->setApi($this);
    }


    /**
     * @param array $resources
     * @throws BindingResolutionException
     */
    public function resources(array $resources)
    {
        foreach ($resources as $name => $controller) {
            $this->resource($name, $controller);
        }
    }


    /**
     * @param string $name
     *
     * @return PathParameter
     */
    public function routeParameter(string $name): PathParameter
    {
        return $this->parameters[ $name ] = new PathParameter(compact('name'));
    }


    /**
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     * @throws \Exception
     */
    public function __call(string $method, array $parameters)
    {
        if (in_array($method, $this->passthru)) {
            return call_user_func_array([ $this->router, $method ], $parameters);
        }

        if (in_array($method, $this->passthruVerbs)) {
            $route = call_user_func_array([ $this->router, $method ], $parameters);

            return $this->getEndpointByUri($route->uri())
                        ->getOperation($method, $route, $this->parameters);
        }

        return $this->macroCall($method, $parameters);
    }


    /**
     * @param string $uri
     *
     * @return Endpoints\Endpoint
     * @throws \Exception
     */
    public function getEndpointByUri(string $uri): Endpoints\Endpoint
    {
        $uri = $this->cleanUpRouteUri($uri);

        $paths = $this->swagger->getPaths();

        if (! $paths->has($uri)) {
            $paths->set($uri, new Endpoints\Endpoint());
        }

        return $paths->get($uri);
    }


    /**
     * @param string $uri
     *
     * @return string
     */
    protected function cleanUpRouteUri(string $uri): string
    {
        $basePath = trim($this->swagger->getBasePath(), '/');
        $uri      = preg_replace("/^{$basePath}/", '', $uri);
        return '/' . trim($uri, '/');
    }


    /**
     * @param string $uri
     * @param array  $resources
     *
     * @return Endpoints\Operation
     * @throws \Exception
     */
    public function aggregate(string $uri, array $resources): Endpoints\Operation
    {
        $controller = AggregateController::class;

        $route = $this->router->get($uri, "\\{$controller}@index")
                              ->defaults('resources', $resources);

        return $this->getEndpointByUri($route->uri())
                    ->getOperation('get', $route);
    }


    /**
     * @param array|string $models
     * @throws BindingResolutionException
     */
    public function models($models)
    {
        $this->app->make(Endpoints\ModelsEndpointRegistry::class)->add(
            is_array($models) ? $models : func_get_args()
        );
    }


    /**
     * Get the path to the API cache file.
     * @return string
     */
    public function getCachedApiPath(): string
    {
        return $this->app->bootstrapPath() . '/cache/api.json';
    }
}
