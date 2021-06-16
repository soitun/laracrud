<?php


namespace LaraCrud\Builder\Test\Methods;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use LaraCrud\Services\ModelRelationReader;

abstract class ControllerMethod
{

    protected array $testMethods = [];
    /**
     * List of full namespaces that will be import on top of controller.
     *
     * @var array
     */
    protected array $namespaces = [];

    /**
     * Whether its an API method or not.
     *
     * @var bool
     */
    protected bool $isApi = false;

    /**
     * @var \ReflectionMethod
     */
    protected $reflectionMethod;

    /**
     * @var \Illuminate\Routing\Route
     */
    protected $route;

    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $parentModel;

    /**
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    /**
     * @var string
     */
    protected string $modelFactory;

    /**
     * @var string
     */
    protected string $parentModelFactory;

    public array $authMiddleware = ['auth', 'auth:sanctum', 'auth:api'];

    /**
     * @var bool
     */
    protected bool $isSanctumAuth = false;

    /**
     * @var bool
     */
    protected bool $isPassportAuth = false;

    /**
     * @var bool
     */
    protected bool $isWebAuth = false;

    /**
     * @var bool
     */
    public static bool $hasSuperAdminRole = false;


    protected ModelRelationReader $modelRelationReader;

    public static array $ignoreDataProviderRules = [
        'nullable',
        'string',
        'numeric',
    ];

    /**
     * ControllerMethod constructor.
     *
     * @param \ReflectionMethod         $reflectionMethod
     * @param \Illuminate\Routing\Route $route
     */
    public function __construct(\ReflectionMethod $reflectionMethod, Route $route)
    {
        $this->reflectionMethod = $reflectionMethod;
        $this->route = $route;
    }

    /**
     * @return static
     */
    public abstract function before();

    /**
     * Get Inside code of a Controller Method.
     *
     * @return string
     *
     * @throws \ReflectionException
     */
    public function getCode(): string
    {
        $this->before();
        return implode("\n", $this->testMethods);
    }

    /**
     * Get list of importable Namespaces.
     *
     * @return array
     */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return $this
     */
    public function setModel(Model $model): self
    {
        $this->model = $model;
        $this->modelRelationReader = (new ModelRelationReader($model))->read();
        $this->namespaces[] = 'use ' . get_class($model);

        return $this;
    }

    /**
     * Set Parent Model when creating a child Resource Controller.
     *
     * @param \Illuminate\Database\Eloquent\Model $parentModel
     *
     * @return \LaraCrud\Builder\Test\Methods\ControllerMethod
     */
    public function setParent(Model $parentModel): self
    {
        $this->parentModel = $parentModel;
        $this->namespaces[] = 'use ' . get_class($parentModel);

        return $this;
    }

    /**
     * @return string
     */
    protected function getModelFactory(): string
    {
        return $this->modelFactory;
    }

    /**
     * @return string
     */
    protected function getParentModelFactory(): string
    {
        return $this->parentModelFactory;
    }

    /**
     * Whether Current route need Auth.
     *
     * @return bool
     */
    protected function isAuthRequired(): bool
    {
        $auth = array_intersect($this->authMiddleware, $this->route->gatherMiddleware());

        if (count($auth) > 0) {
            if (in_array('auth', $auth)) {
                $this->isWebAuth = true;
            }
            if (in_array('auth:sanctum', $auth)) {
                $this->isSanctumAuth = true;
            }
            if (in_array('auth:api', $auth)) {
                $this->isPassportAuth = true;
            }
            return true;
        }
        return false;
    }

    /**
     * @return false|string
     */
    protected function getSanctumActingAs($actionAs)
    {
        if (!$this->isSanctumAuth) {
            return false;
        }
        $this->namespaces[] = 'use Laravel\Sanctum\Sanctum';
        return 'Sanctum::actingAs(' . $actionAs . ', [\'*\']);';
    }

    /**
     * @return false|string
     */
    protected function getPassportActingAs($actionAs)
    {
        if (!$this->isPassportAuth) {
            return false;
        }

        $this->namespaces[] = 'use Laravel\Passport\Passport';

        return 'Passport::actingAs(' . $actionAs . ', [\'*\']);';
    }

    protected function getWebAuthActingAs($actionAs)
    {
        if (!$this->isWebAuth) {
            return false;
        }

        return 'actingAs(' . $actionAs . ')->';
    }

    /**
     * Whether current application has Super Admin Role.
     *
     * @return bool
     */
    protected function hasSuperAdminRole(): bool
    {
        return static::$hasSuperAdminRole;
    }

    /**
     *
     */
    protected function getRoute()
    {
        $params = '';
        $name = $this->route->getName();
        if (empty($this->route->parameterNames())) {
            return 'route("' . $name . '")';
        }
        foreach ($this->route->parameterNames() as $parameterName) {
            if (strtolower($parameterName) == strtolower($this->modelRelationReader->getShortName())) {
                $value = $this->getModelVariable() . '->' . $this->model->getRouteKeyName();
            } else {
                $value = '';
            }
            $params .= '"' . $parameterName . '" => ' . $value . ', ';
        }

        return 'route("' . $name . '",[' . $params . '])';
    }

    protected function getModelVariable(): string
    {
        return '$' . lcfirst($this->modelRelationReader->getShortName());
    }

    protected function getApiActingAs(string $actionAs)
    {
        if ($this->isSanctumAuth) {
            return $this->getSanctumActingAs($actionAs);
        }
        if ($this->isPassportAuth) {
            return $this->getPassportActingAs($actionAs);
        }
        return '';
    }

    protected function getGlobalVariables($actionAs = '$user'): array
    {
        return [
            'modelVariable' => $this->getModelVariable(),
            'modelShortName' => $this->modelRelationReader->getShortName(),
            'route' => $this->getRoute(),
            'modelMethodName' => Str::snake($this->modelRelationReader->getShortName()),
            'apiActingAs' => $this->getApiActingAs($actionAs),
            'webActingAs' => $this->isWebAuth ? $this->getWebAuthActingAs($actionAs) : '',
            'table' => $this->model->getTable(),
            'assertDeleted' => $this->modelRelationReader->isSoftDeleteAble() ? 'assertSoftDeleted' : 'assertDeleted',
        ];
    }


    public function getCustomRequestClassRules(): array
    {
        $rules = [];
        try {
            foreach ($this->reflectionMethod->getParameters() as $parameter) {
                if ($parameter->hasType()) {
                    if (is_subclass_of($parameter->getType()->getName(), FormRequest::class)) {
                        $className = $parameter->getType()->getName();
                        $rfm = new \ReflectionMethod($parameter->getType()->getName(), 'rules');
                        $rules = $rfm->invoke(new $className);
                    }
                }
            }
        } catch (\Exception $e) {
            return $rules;
        }

        return $rules;
    }

    public function generatePostData($update = false): string
    {
        $data = '';
        $modelVariable = $update == true ? '$new' . $this->modelRelationReader->getShortName() : $this->getModelVariable();
        $rules = $this->getCustomRequestClassRules();
        foreach ($rules as $field => $rule) {
            $data .= "\t\t\t" . '"' . $field . '" => ' . $modelVariable . '->' . $field . ',' . PHP_EOL;
        }

        return $data;
    }

    /**
     * @return string
     */
    public function generateDataProvider(): string
    {
        $data = '';
        $rules = $this->getCustomRequestClassRules();
        foreach ($rules as $field => $rule) {
            $listOfRules = is_array($rule) ? $rule : explode("|", $rule);
            foreach ($listOfRules as $listOfRule) {
                if (is_object($listOfRule)) {
                    continue;
                }
                if (in_array($listOfRule, static::$ignoreDataProviderRules)) {
                    continue;
                }
                $data .= "\t\t\t" . '"' . "The $field must be $listOfRule" . '"' . ' => ["' . $field . '"," " ],' . PHP_EOL;
            }

        }

        return $data;
    }
}
