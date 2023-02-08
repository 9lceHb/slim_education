<?php

use Slim\Factory\AppFactory;
use DI\Container;
use Slim\Middleware\MethodOverrideMiddleware;

use function Symfony\Component\String\s;

require_once __DIR__ . '/../vendor/autoload.php';

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

function deleteUser($id)
{
    $users = json_decode(file_get_contents(realpath("data/users.json")), true);
    $updatedUsers = collect($users)->reject(function ($value, $key) use ($id) {
        return $value['id'] === $id;
    });
    file_put_contents(realpath("data/users.json"), json_encode($updatedUsers));
}

function editUser($user)
{
    $users = json_decode(file_get_contents(realpath("data/users.json")), true);
    $editKey = collect($users)->filter(fn($oldUser) => ($oldUser['id'] === $user['id']))->keys()->toArray()[0];
    $newUsers = collect($users)->replace([$editKey => $user]);
    file_put_contents(realpath("data/users.json"), json_encode($newUsers));
}

function saveUser($user)
{
    $users = json_decode(file_get_contents(realpath("data/users.json")), true);
    $newId = array_reduce($users, function ($acc, $user) {
        return max($acc, $user['id']);
    }, 0) + 1;
    $user['id'] = $newId;
    $users[] = $user;
    file_put_contents(realpath("data/users.json"), json_encode($users));
}

function validator($user)
{
    $errors = [];
    if (strlen($user['firstName']) <= 4) {
        $errors['firstName'] = 'incorrect name';
    }

    if (strlen($user['email']) <= 8) {
        $errors['email'] = 'incorrect email';
    }
    return $errors;
}

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->add(MethodOverrideMiddleware::class);
$app->addErrorMiddleware(true, true, true);

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'index.phtml');
});

$app->get('/users', function ($request, $response) {
    $messages = $this->get('flash')->getMessages();
    // print_r($messages);
    $term = $request->getQueryParam('term');
    $users = json_decode(file_get_contents(realpath("data/users.json")), true);
    $result = array_filter(
        $users,
        fn($user) => empty($term) ? true : s($user['firstName'])->ignoreCase()->startsWith($term)
    );
    $params = [
        'users' => $result,
        'term' => $term,
        'flash' => $messages
    ];
    return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('users');

$router = $app->getRouteCollector()->getRouteParser();

$app->post('/users', function ($request, $response) use ($router) {
    $user = $request->getParsedBodyParam('user');
    $link = $router->urlFor('users');
    $errors = validator($user);
    if (count($errors) === 0) {
        saveUser($user);
        $this->get('flash')->addMessage('success', 'User was added successfully');
        return $response->withRedirect($link, 302);
    }
    $params = [
        'user' => $user,
        'errors' => $errors
    ];
    return $this->get('renderer')->render($response, "users/new.phtml", $params);
});

$app->get('/users/new', function ($request, $response) {
    $params = [
        'user' => ['firstName' => '', 'email' => ''],
        'errors' => []
    ];
    return $this->get('renderer')->render($response, "users/new.phtml", $params);
})->setName('newUsers');

$app->get('/users/{id}', function ($request, $response, $args) {
    $messages = $this->get('flash')->getMessages();
    $id = (int)$args['id'];
    $users = collect(json_decode(file_get_contents(realpath("data/users.json")), true));
    $user = $users->filter(fn($user) => ($user['id'] === $id))->values()->toArray();
    if (empty($user)) {
        return $response->withStatus(404)->write('user not find');
    }
    $user = $user[0];
    $params = [
        'user' => $user,
        'flash' => $messages
    ];
    return $this->get('renderer')->render($response, "users/show.phtml", $params);
})->setName('user');

$app->get('/users/{id}/edit', function ($request, $response, array $args) {
    $id = (int)$args['id'];
    $users = collect(json_decode(file_get_contents(realpath("data/users.json")), true));
    $user = $users->filter(fn($user) => ($user['id'] === $id))->values()->toArray();
    if (empty($user)) {
        return $response->withStatus(404)->write('user not find');
    }
    $user = $user[0];
    $params = [
        'user' => $user,
        'errors' => []
    ];
    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
})->setName('editUser');

$app->patch('/users/{id}', function ($request, $response, array $args) use ($router) {
    $id = (int)$args['id'];
    // $users = collect(json_decode(file_get_contents(realpath("data/users.json")), true));
    // $user = $users->filter(fn($user) => ($user['id'] === $id))->values()->toArray();
    $userNewParams = $request->getParsedBodyParam('user');
    $errors = validator($userNewParams);
    $userNewParams['id'] = $id;
    if (count($errors) === 0) {
        editUser($userNewParams);
        $this->get('flash')->addMessage('success', 'User was updated successfully');
        $link = $router->urlFor('user', ['id' => $id]);
        return $response->withRedirect($link, 302);
    }
    $params = [
        'user' => $userNewParams,
        'errors' => $errors
    ];
    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
});

$app->delete('/users/{id}', function ($request, $response, array $args) use ($router) {
    $id = (int)$args['id'];
    deleteUser($id);
    $this->get('flash')->addMessage('success', 'User was deleted');
    $link = $router->urlFor('users');
    return $response->withRedirect($link, 302);
});

$app->run();
