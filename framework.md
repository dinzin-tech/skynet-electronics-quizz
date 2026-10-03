# simple-mvc-app

`simple-mvc-app` is an implementation example showcasing how to build a web application using the `dinzin-tech/simple-mvc` framework. It serves as a boilerplate for developers and AI agents to understand the structural paradigms, routing mechanisms, and database interactions provided by the underlying framework.

## 🎯 Architecture Overview

This app strictly follows the MVC (Model-View-Controller) architecture:
- **`app/controllers`**: Handlers for HTTP requests. Controllers here map directly to routes using docblock annotations.
- **`app/models`**: Active-record style abstractions linking to database tables, empowered by a fluent Query Builder.
- **`app/views`**: Contains Twig-based templates for the UI.
- **`public/`**: The document root containing `index.php`, bootstrapping the core Application `Kernel` safely away from internal logic files.
- **`commands/`**: Stores custom CLI commands for code generation and maintenance.

## 🚀 Quickstart & Installation

You can initialize a fresh project using this skeleton. 

### 1. Initialize via Composer
To create a new project based on this skeleton, run:

```bash
composer create-project --repository="{\"type\":\"vcs\",\"url\":\"https://github.com/dinzin-tech/simple-mvc-app.git\"}" dinzin-tech/simple-mvc-app path-to-your-project dev-main
```

*Note: Since this is currently a local development version, you may need to use a local repository reference in your composer config or just clone this repository and run `composer install`.*

### 2. Post-Initialization
The `composer create-project` command automatically runs a post-installation script that:
- Initializes your `.env` file.
- Creates the `storage/` directory for caching and logs.

### 3. Run the Development Server
```bash
composer serve:dev
```
Your app will be available at `http://localhost:8000`.

### 4. Setup Database
Adjust your the `.env` file:
```env
APP_SECRET=your_random_secret_key
DEFAULT_DB_HOST=127.0.0.1
DEFAULT_DB_DATABASE=your_database
DEFAULT_DB_USER=root
DEFAULT_DB_PASSWORD=secret
```

### 5. Generate Application Key
You can generate a secure application secret using the console:
```bash
php bin/console keygenerate
```

Run migrations:
```bash
php bin/console migrations:exec run
```
## 🧠 For Developers and LLM Agents

### 1. Controllers & Routing Configuration
Routing is decoupled from a central configuration file. Instead, routes are discovered via reflections reading annotations from your controllers. 

*Example from `app/controllers/HomeController.php`:*
```php
<?php
namespace App\Controllers;
use Core\Controller;
use Core\Http\Request;

class HomeController extends Controller {
    /**
     * @Route(path="/", methods="GET,POST", name="home")
     */
    public function index(Request $request) {
        return $this->render('home/index.html.twig', ['title' => 'Home']);
    }
}
```
**Agents Note:** When creating new pages, you *must* add the `@Route` annotation accurately for the `Core\Router` component to map the URL correctly.

### 2. Models & Query Builder Usage
Models in this app correspond directly to tables (e.g. `User` maps to `users`). They extend `Core\Model`.
We highly recommend utilizing the built-in fluent Query Builder for data retrieval to protect against SQL injections and maintain clean code.

*Example from `app/models/User.php`:*
```php
<?php
namespace App\Models;
use Core\Model;

class User extends Model {
    public function __construct() {        
        $this->table = 'users';
        parent::__construct(); // No args required here!
    }

    public function retrieveActiveUsers() {
        // Utilizing the query builder
        return self::query()->where('status', 'active')->get();
    }
    
    public function getActiveUsersWithProfiles() {
        // Complex joins are natively supported
        return self::query()
            ->select('users.*', 'profiles.bio')
            ->join('profiles', 'users.id', '=', 'profiles.user_id')
            ->where('status', 'active')
            ->get();
    }
}
```
**Agents Note:** `Model` objects instantiate their own connection to the Database Singleton. Never invoke `new Database()` manually inside the model. Always utilize `self::query()` to begin building an interaction string. Raw SQL queries are supported via the `->raw($sql, $bindings)` method.

### 3. Views
All visual elements are routed through **Twig**, which resides in the `app/views` directory. The view files end with `.twig`. Data is comfortably passed to the view via the array in the `$this->render()` controller method.

### 4. Testing
The application uses **PHPUnit** for both Unit and Feature testing.

#### Running Tests
You can run all tests using the following command:
```bash
./vendor/bin/phpunit
```

Or run specific suites:
```bash
# Run only Unit tests
./vendor/bin/phpunit --testsuite Unit

# Run only Feature tests
./vendor/bin/phpunit --testsuite Feature
```

#### Directory Structure
- **`tests/Unit/`**: For testing isolated logic (e.g., service classes, models without DB).
- **`tests/Feature/`**: For testing HTTP routes, controller outputs, and integration flows.
- **`tests/TestCase.php`**: The base test class where the framework is booted for tests.

#### Writing Tests
All test classes must extend `Tests\TestCase`. You can use the `$this->get($uri)` helper in Feature tests to simulate requests to your application.

*Example Feature Test:*
```php
public function it_loads_the_homepage()
{
    $response = $this->get('/');
    $this->assertEquals(200, $response->getStatusCode());
}
#### Testing Console Commands
You can also test custom console commands using the `$this->console()` helper in your feature tests. This helper captures the output of the command for you to assert against.

*Example Console Test:*
```php
public function it_can_run_hello_command()
{
    $output = $this->console('hello Antigravity');
    $this->assertStringContainsString('Hello, Antigravity!', $output);
}
```

### 5. Linting and Quality
Linting instructions are based on PSR12 compliance:
```bash
# Linting Checks
composer lint

# Auto-fix linting issues
composer lint-fix
```

### 5. Console Commands & Generators
The framework provides an active CLI helper via the `bin/console` executable. It helps generate boilerplate code and manage database migrations rapidly.

*Available Commands:*
- `php bin/console make:controller ControllerName` (Generates a new Controller)
- `php bin/console make:model ModelName` (Generates a new Model)
- `php bin/console make:view path/viewname` (Generates a new Twig View)
- `php bin/console migrations:create` (Generates a new blank migration file)
- `php bin/console migrations:exec` (Executes pending migrations)
- `php bin/console help` (Lists all commands)

*Note: You can declare custom commands by placing your classes inside the `app/Commands` directory. They will automatically be discovered by the CommandManager.*

## 🛠 Asset Management 
This project integrates with a gulp-based asset management system to handle compilation, copying, and minification. Use the below commands to interact with standard node modules (requires NPM & Node.js).
```bash
npm install
npm run gulp
```
