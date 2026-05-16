## Project Structure

```
docker-composer/
├─╴.github/      — GitHub Actions workflows.
├─╴config/       — Configuration files for Laravel support.
├─╴features/     — Behat feature test suite, written in Gerkin.
│  └─╴bootstrap/ — Behat Context classes.
├─╴src/          — Source files organized by domain. Follows PSR-4.
│  └─╴Laravel/   — Source code related to Laravel support.
├─╴tests/        — Unit tests.
└─╴vendor/       — Vendor packages, installed by Composer.
```

## Instructions
In commit messages use conventional commits and provide justification of the changes in the body.

### Plan Mode
At the end of each plan, give me a list of unresolved questions to answer, if any.
When asking the user to choose an approach, consider whether chaining multiple approaches is also a valid or even the recommended option.

### Tests
When writing unit tests, create a TestCase class for each class being tested.
At the end of every task, execute these commands to ensure the quality of the code:
- `composer cs-fix`
- `composer check`

#### Feature Tests
When adding new behavior, write a Behat feature spec that covers it. When changing behavior, update the corresponding Behat feature spec.

#### Coverage
All unit tests are required to have both a branch and line coverage of 100%.

### Architecture
DRY: Don't Repeat Yourself — before adding new code, inspect existing abstractions and extend/reuse them.
Framework integrations belong in framework-named subdirectories under `src/`.
Do not duplicate code when a shared abstraction can cover the behavior.

### Coding Style
All PHP code must adhere to PER Coding Style, which includes PSR-1: Basic Coding Standard.
Each class must be in a file by itself.

### PHPDoc
Add descriptive PHPDoc comments to all Structural Elements in PHP code under `src/`. For functions and methods include the return type, and the `@param` and `@return` tags for every argument.

When writing PHPDocs, observe this format:

```php
class Foo
{
    /**
     * Constants don't need a `@var` doctag, since their type is implied.
     */
    public const FOO = 'foo';
    
    /**
     * Property desciptions go here.
     * 
     * `@var` doctags never get a description:
     * 
     * @var list<string>
     */
    protected array $bar;

    /**
     * The `@var` doctag is omitted if the type is unambiguous.
     */
    private Baz $baz;
    
    /**
     * The `@return` doctag is omitted if the return type is `void`. 
     */
    public function doSomething(): void
    {
        // Imagine this method does something.
    }

    /**
     * The first line should be a short description, no Markdown allowed here.
     * 
     * Here, provide a high-level explanation of what the function does and what
     * the use cases are. Aim for a line length of 80 characters or fewer.
     *
     * You can use as many lines and all the Markdown you want.
     * - reference arguments by making the text __bold__
     * - reference scalar types and literals with backticks, so `string` and
     *   `"foo"`
     * - reference non-scalar types as {@see Foo}
     * - import referenced classes, do not use FQN
     * 
     * If appropriate, e.g. if not completely clear from the description 
     * signature alone, provide some examples:
     *
     *     // 4 spaces indent Code blocks.
     *     foo($foo, $bar, 'foo'); // returns $foo
     *     foo($foo, $bar, 'baz'); // returns $bar
     *
     * Keep a blank line before the first doc tag:
     *
     * @template TFoo of Foo
     * @template TBar of Bar
     *
     * @param  TFoo  $one
     *   Place the description of a doc tag on the next line, indented by 2
     *   spaces.
     *
     * @param  TBar  $two
     *   Doc tags with a description should be surrounded by a blank line.
     * 
     * @param  string  $three
     *   Only `@param` doc tags have their type and name surrounded by 2 spaces.
     *
     * @return ($three is "foo" ? TFoo : TBar)
     *   Returns __one__ if __three__ is `"foo"`, __two__ otherwise.
     * 
     * @throws RuntimeException
     *   Thrown if __three__ is an empty string.
     */
    function foo(Foo $one, Bar $two, string $three = "foo"): Foo|Bar {}
}
```

### Tools
If a tool, command or integration fails that one would expect to be working, do not try a different approach. Instead, investigate the problem and suggest a fix to the user.
