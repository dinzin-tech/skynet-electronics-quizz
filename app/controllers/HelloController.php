<?php

namespace App\Controllers;

use Core\Controller;
use Core\Http\Request;
use Core\Http\Response;

class HelloController extends Controller
{
    /**
     *  @Route(path="/hello", methods="GET", name="hello")
     */
    public function index(Request $request)
    {
        // Your code here

        // return respnse using template (make sure template 'hello.htm.twig' exists in /views)
        // return $this->render('hello.htm.twig', ['content' => 'Welcome to hello']);

        return new Response("Welcome to hello", 200);
    }
}
