<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;

$container = new Container();
$container->set('renderer', function () {
    // Параметром передается базовая директория, в которой будут храниться шаблоны
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/users', function ($request, $response) {
    $users = getUsers();
    $username = $request->getQueryParam('username');

    if ($username) {
        $params = ['users' => array_filter($users, fn ($user) => str_contains(strtolower($user->nickname), strtolower($username)))];
    } else {
        $params = ['users' => $users];
    }

    $params['username'] = $username;

    return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('users');

$app->get('/users/new', function ($request, $response) {
    $params = ['user' => []];

    return $this->get('renderer')->render($response, 'users/new.phtml', $params);
})->setName('create-user');

$app->post('/users/new', function ($request, $response) use ($router) {
    $user = $request->getParsedBodyParam('user', []);

    if (!$user) {
        $params = compact('user');
        return $this->get('renderer')->render($response, 'users/new.phtml', $params);
    }

    $users = getUsers();
    $ids = array_column($users, 'id');
    rsort($ids);

    $lastId = $ids[0] ?? 0;

    $users[] = array_merge($user, ['id' => ++$lastId]);

    saveUsers($users);

    return $response->withRedirect($router->urlFor('users'), 302);
})->setName('store-user');

$app->get('/users/{id}', function ($request, $response, $args) {
    $users = getUsers();
    $foundUsers = array_filter($users, fn ($user) => $user->id == $args['id']);

    if (!$foundUsers) {
        return $response->withStatus(404);
    }

    $params = ['id' => $args['id'], 'nickname' => 'user-' . $args['id']];

    return $this->get('renderer')->render($response, 'users/show.phtml', $params);
})->setName('show-user');

function getUsers(): array
{
    $parts = [__DIR__, 'db', 'users'];
    $path = realpath(implode('/', $parts));
    return json_decode(file_get_contents($path)) ?? [];
}

function saveUsers(array $users): void
{
    $parts = [__DIR__, 'db', 'users'];
    $path = realpath(implode('/', $parts));
    file_put_contents($path, json_encode($users));
}

$app->run();
