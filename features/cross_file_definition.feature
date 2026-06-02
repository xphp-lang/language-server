Feature: Cross-file go to definition
  As a developer editing xphp
  I want "Go to Definition" on a type or method to jump to its declaration
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

  Scenario: Jump to a class declared in another file
    When I request "textDocument/definition" on "Collection" at line 9 of "Use.xphp"
    Then the response points to "Containers/Collection.xphp"
    And the target range covers the "Collection" class name

  Scenario: Jump to an imported class declared in another file
    When I request "textDocument/definition" on "User" at line 9 of "Use.xphp"
    Then the response points to "Models/User.xphp"
    And the target range covers the "User" class name

  Scenario: Jump through a generic method to its declaration
    When I request "textDocument/definition" on "first" at line 10 of "Use.xphp"
    Then the response points to "Containers/Collection.xphp"
    And the target range covers the "first" method declaration
