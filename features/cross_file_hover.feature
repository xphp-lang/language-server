Feature: Cross-file hover
  As a developer editing xphp
  I want hover to show a symbol's declaration and type
  Even when the file that declares it is not currently open in the editor

  Background:
    Given the file at "Containers/Collection.xphp" contains the following lines:
      """
      <?php

      declare(strict_types=1);

      namespace App\Containers;

      class Collection<T>
      {
          private T[] $items;

          public function __construct(T ...$items)
          {
              $this->items = $items;
          }

          public function first(): ?T
          {
              return $this->items[0] ?? null;
          }

          public function all(): T[]
          {
              return $this->items;
          }
      }
      """
    And the file at "Models/User.xphp" contains the following lines:
      """
      <?php

      declare(strict_types=1);

      namespace App\Models;

      final class User
      {
          public function __construct(public readonly string $name)
          {
          }
      }
      """
    And the file at "Use.xphp" contains the following lines:
      """
      <?php

      declare(strict_types=1);

      namespace App;

      use App\Containers\Collection;
      use App\Models\User;

      $users = new Collection<User>(new User('Alice'), new User('Bob'));
      $first = $users->first();
      $all = $users->all();
      """
    And the FQN index has been warmed on initialize

  Scenario: Hover over an imported class declared in another file
    When I request "textDocument/hover" on "App\Models\User" at line 7 of "Use.xphp"
    Then the hover contents describe the class "App\Models\User"

  Scenario: Hover over a generic receiver method declared in another file
    When I request "textDocument/hover" on "first" at line 10 of "Use.xphp"
    Then the hover contents show the substituted signature "first(): ?User"

  Scenario: Hover over a generic array-return method declared in another file
    When I request "textDocument/hover" on "all" at line 11 of "Use.xphp"
    Then the hover contents show the substituted signature "all(): User[]"
