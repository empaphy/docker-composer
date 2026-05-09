## General Instructions
In commit messages use conventional commits and provide justification of the changes in the body.
In all interactions and plans be extremely concise — sacrifice grammar for the sake of conciseness. Conciseness alone does not justify omitting information or intent.

## Plan Mode
Make plans extremely concise — sacrifice grammar for the sake of concision. Conciseness alone does not justify omitting information or intent.
At the end of each plan, give me a list of unresolved questions to answer, if any.

## Tests
When writing unit tests, create a TestCase class for each class being tested.
At the end of every task, execute these commands to ensure the quality of the code:
- `composer style-fix`
- `composer stan`
- `composer test`

## PHPStan
Prefer clear, performant code over reshaping code only to satisfy PHPStan.
Treat PHPStan findings as likely real; fix root causes first.
If PHPStan cannot model valid runtime behavior, use the narrowest fix:
 1. add explicit control flow or `assert()` when it improves clarity;
 2. otherwise add a targeted `@phpstan-ignore <identifier>` on the exact line.
Do not add broad suppressions, baselines, or unclear type workarounds.

## Coding Style
All PHP code must adhere to PER Coding Syle 3.0, which also includes PSR-1: Basic Coding Standard.
Files should _either_ declare symbols _or_ cause side-effects but not both.

## PHPDoc
Add descriptive PHPDoc comments to all Structural Elements in PHP code under `src/`. Include descriptive `@param` and `@return` tags for all argument and return types, and `@var` tags for all parameters.

When writing PHPDocs, observe this format:

```php
class 
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
    private Baz baz():

    /**
     * The first line should be a short description with no Markdown.
     * 
     * Here you can add as many lines and all the Markdown you want.
     * - reference arguments by making the text __bold__
     * - reference scalar types and literals with backticks, so `string` and `"foo"`
     * - reference non-scalar types as {@see Foo}
     * - import referenced classes, do not use FQN
     * 
     *     // 4 spaces indent Code blocks.
     *     foo(1, 2, 'three');
     *
     * Keep a blank line before the first doctag:
     *
     * @template TFoo of Foo
     * @template TBar of Bar
     *
     * @param  TFoo  $one
     *   Place the description of a doctag on the next line, indented by 2
     *   spaces.
     *
     * @param  TBar  $two
     *   Doctags with a description should be surrounded by a blank line.
     * 
     * @param  string  $three
     *   `@param` doctags should have their type and argument name surrounded
     *   by 2 spaces
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

## Tools
If a tool, command or integration fails that one would expect to be working, do not try a different approach. Instead, investigate the problem and suggest a fix to the user.
