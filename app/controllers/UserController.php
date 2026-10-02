<?php

namespace App\Controllers;

use Core\Controller;
use Core\Http\Request;
use Core\Http\Response;

class UserController extends Controller
{
    /**
     *  @Route(path="/user", methods="GET", name="user")
     */
    public function index(Request $request)
    {
        // Your code here

        // return respnse using template (make sure template 'user.htm.twig' exists in /views)
        // return $this->render('user.htm.twig', ['content' => 'Welcome to user']);

        return new Response("Welcome to user", 200, ['content-type' => 'application/json']);
    }
}
