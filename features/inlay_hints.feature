Feature: Inlay hints for substituted variable types
  As a developer editing xphp
  I want inline type hints after assignments
  So that I can see the concrete type a generic method resolved to

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

  Scenario: Hint the concrete type of a generic instantiation
    When I request "textDocument/inlayHint" for the visible range of "Use.xphp"
    Then an inlay hint ": Collection<User>" is rendered after "$users" on line 9

  Scenario: Hint the substituted return type of a generic method call
    When I request "textDocument/inlayHint" for the visible range of "Use.xphp"
    Then an inlay hint ": ?User" is rendered after "$first" on line 10

  Scenario: Hint the substituted array-of-T return type
    When I request "textDocument/inlayHint" for the visible range of "Use.xphp"
    Then an inlay hint ": User[]" is rendered after "$all" on line 11
