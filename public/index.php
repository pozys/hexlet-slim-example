<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware;

session_start();

$container = new Container();
$container->set(
    'renderer',
    function () {
        // Параметром передается базовая директория, в которой будут храниться шаблоны
        return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
    }
);

$container->set(
    'flash',
    function () {
        return new \Slim\Flash\Messages();
    }
);

$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

$router = $app->getRouteCollector()->getRouteParser();

$app->get(
    '/',
    function ($request, $response) use ($router) {
        if (!isAuthenticated()) {
            return $response->withRedirect($router->urlFor('login'), 302);
        }

        $users = getUsers($request);
        $username = $request->getQueryParam('username');

        if ($username) {
            $params = ['users' => array_filter($users, fn ($user) => str_contains(strtolower($user->nickname), strtolower($username)))];
        } else {
            $params = ['users' => $users];
        }

        $params['username'] = $username;
        $params['flash'] = $this->get('flash')->getMessages();

        return $this->get('renderer')->render($response, 'users/index.phtml', $params);
    }
)->setName('users');

$app->get(
    '/users/new',
    function ($request, $response) {
        $params = ['user' => [], 'errors' => []];

        return $this->get('renderer')->render($response, 'users/new.phtml', $params);
    }
)->setName('create-user');

$app->post(
    '/users',
    function ($request, $response) use ($router) {
        $user = $request->getParsedBodyParam('user', []);

        $params = compact('user');

        if (!$user) {
            $params['errors'] = [];
            return $this->get('renderer')->render($response, 'users/new.phtml', $params);
        }

        $errors = validate($user);

        if (count($errors) > 0) {
            $params['errors'] = $errors;
            $response = $response->withStatus(402);

            return $this->get('renderer')->render($response, 'users/new.phtml', $params);
        }

        $params['errors'] = [];

        $users = getUsers($request);
        $ids = array_column($users, 'id');
        rsort($ids);

        $lastId = $ids[0] ?? 0;
        $user['id'] = ++$lastId;
        $users[] = $user;

        $response = saveUsers($response, $users);

        $this->get('flash')->addMessage('success', 'User was added successfully');

        return $response->withRedirect($router->urlFor('users'), 302);
    }
)->setName('store-user');

$app->put(
    '/users/{id}',
    function ($request, $response, array $args) use ($router) {
        $data = $request->getParsedBodyParam('user', []);
        $id = $args['id'];

        $params = compact('user');

        if (!$data) {
            $params = ['user' => [], 'errors' => []];
            $response = $response->withStatus(422);

            return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
        }

        $errors = validate($data);

        if (count($errors) > 0) {
            $params = compact('errors');
            $params['user'] = $data;
            $response = $response->withStatus(422);

            return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
        }

        $params['errors'] = [];

        $user = findUser($request, $id);

        if (!$user) {
            return $response->withStatus(404);
        }

        $user['nickname'] = $data['nickname'];
        $user['email'] = $data['email'];

        $response = saveUser($request, $response, $user);

        $this->get('flash')->addMessage('success', 'User was updated successfully');

        return $response->withRedirect($router->urlFor('showUser', compact('id')), 302);
    }
)->setName('update-user');

$app->get(
    '/users/{id}',
    function ($request, $response, $args) {
        $user = findUser($request, $args['id']);

        if (!$user) {
            return $response->withStatus(404);
        }

        $params = compact('user');

        return $this->get('renderer')->render($response, 'users/show.phtml', $params);
    }
)->setName('showUser');

$app->get(
    '/users/{id}/edit',
    function ($request, $response, array $args) {
        $user = findUser($request, $args['id']);

        if (!$user) {
            return $response->withStatus(404);
        }

        $errors = [];
        $params = compact('user', 'errors');

        return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
    }
)->setName('editUser');

$app->delete(
    '/users/{id}',
    function ($request, $response, array $args) use ($router) {
        $id = $args['id'];

        $response = deleteUser($request, $response, $id);
        $re = deleteUser($request, $response, $id);


        $errors = [];
        $params = compact('user', 'errors');

        $this->get('flash')->addMessage('success', 'User was deleted successfully');

        return $response->withRedirect($router->urlFor('users'), 302);
    }
)->setName('editUser');

$app->post('/login', function ($request, $response) use ($router) {
    $login = trim($request->getParsedBodyParam('login'));

    if (!$login) {
        return $this->get('renderer')->render($response->withStatus(422), 'users/auth.phtml');
    }

    session_start();

    $_SESSION['login'] = $login;

    return $response->withRedirect($router->urlFor('users'), 302);
});

$app->get('/login', function ($request, $response) use ($router) {
    return $this->get('renderer')->render($response, 'users/auth.phtml');
})->setName('login');

$app->post('/logout', function ($request, $response) use ($router) {
    $_SESSION = [];
    session_destroy();

    return $response->withRedirect($router->urlFor('login'), 302);
});

function getUsers($request): array
{
    return json_decode($request->getCookieParam('users', json_encode([])), true);
}

function saveUser($request, $response, $user)
{
    $users = getUsers($request);
    $users = array_map(fn ($item) => $user['id'] == $item['id'] ? $user : $item, $users);
    return saveUsers($response, $users);
}

function saveUsers($response, array $users)
{
    $encodedUsers = json_encode($users);
    return $response->withHeader('Set-Cookie', "users={$encodedUsers}");
}

function findUser($request, string $id)
{
    $users = getUsers($request);
    $foundUsers = array_filter($users, fn ($user) => $user['id'] == $id);

    if (!$foundUsers) {
        return null;
    }

    return array_values($foundUsers)[0];
}

function deleteUser($request, $response, string $id)
{
    $users = getUsers($request);
    $foundUsers = array_filter($users, fn ($user) => $user['id'] == $id);

    if (!$foundUsers) {
        return;
    }

    array_splice($users, (key($foundUsers) + 1), 1);

    return saveUsers($response, $users);
}

function validate(array $params): array
{
    $errors = array_reduce(
        array_keys($params),
        function ($errors, $name) use ($params) {
            $value = $params[$name];
            switch ($name) {
                case 'nickname':
                    if (!$value) {
                        $errors[$name] = 'Is required';
                    } else if (mb_strlen($value) < 5) {
                        $errors[$name] = 'Nickname must be greater than 4 characters';
                    }
                    break;
                case 'email':
                    if (!$value) {
                        $errors[$name] = 'Is required';
                    }
                default:
                    return $errors;
            }

            return $errors;
        },
        []
    );

    return $errors;
}

function isAuthenticated(): bool
{
    return isset($_SESSION['login']);
}

$app->run();
