<?php

namespace App\Controllers;

use Core\Controller;
use Core\Http\Response;
use Core\Http\Request;
use App\Models\User;

class HomeController extends Controller
{
    /**
     * @Route(path="/", methods="GET,post", name="home")
     */
    public function index($request)
    {
        $form = new \Core\Form();
        $form->add('text', 'name', null, ['label' => 'Name', 'required' => true]);

        $form->add('email', 'email', null, ['label' => 'Email', 'required' => true]);

        $form->add('password', 'password', null, ['label' => 'Password', 'required' => true]);

        // $form->add('submit', 'submit', null, ['label' => 'Submit']);
        // if ($request->isPost()) {
            $form->handle($request);
        if ($form->validate($request) && $form->isSubmitted()) {
            // $user = new User();
            // $user->name = $form->get('name');
            // $user->email = $form->get('email');
            // $user->password = $form->get('password');
            // $user->save();
            print_r($form->getFormData());
        }
        // }

        // print $form->render();
        // exit;


        return $this->render('home/index.html.twig', [
            'name' => 'John Doe',
            'form' => $form->render()
        ]);
    }

    /**
     * @Route(path="/greet", methods="GET,post", name="greeetings")
     */
    public function greet(Request $request)
    {
        $name = $request->get('name', 'Guest');
        $greeting = "Hello, " . htmlspecialchars($name) . "!";

        return new Response($greeting);
    }
}
