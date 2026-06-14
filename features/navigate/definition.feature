Feature: Go to definition
  As a developer editing xphp
  I want "Go to Definition" to jump to a symbol's declaration across files

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

      $users = new Collection::<User>(new User('Alice'), new User('Bob'));
      $first = $users->first();
      """
    And the FQN index has been warmed on initialize

  Scenario: Jump to a generic class declared in another file
    When I request "textDocument/definition" on "Collection" at line 9 of "Use.xphp"
    Then the response points to "Containers/Collection.xphp"
    And the target range covers the "Collection" class name

  Scenario: Jump to an imported class used as a type argument
    When I request "textDocument/definition" on "User" at line 9 of "Use.xphp"
    Then the response points to "Models/User.xphp"
    And the target range covers the "User" class name

  Scenario: Jump through a generic method call to its declaration
    When I request "textDocument/definition" on "first" at line 10 of "Use.xphp"
    Then the response points to "Containers/Collection.xphp"
    And the target range covers the "first" method declaration

  Scenario: Jump to a type argument of a self turbofish
    Given the file at "Models/Plastic.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      class Plastic {}
      """
    And the file at "SelfUse.xphp" contains the following lines:
      """
      <?php
      namespace App;
      use App\Models\Plastic;
      class Crate<T>
      {
          public function copy(): Crate
          {
              return new self::<Plastic>();
          }
      }
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/definition" on "Plastic" at line 7 of "SelfUse.xphp"
    Then the response points to "Models/Plastic.xphp"
    And the target range covers the "Plastic" class name

  Scenario: Jump through a nullsafe method chain to the final method declaration
    # Self-contained fixtures (NOT Background overrides): a warmed FQN index goes
    # stale when a Background file is redefined, so the chain's terminal hop would
    # miss. Fresh files keep the index consistent.
    Given the file at "Chain/Bag.xphp" contains the following lines:
      """
      <?php
      namespace App\Chain;
      class Bag<T>
      {
          public function first(): ?T { return null; }
      }
      """
    And the file at "Chain/Widget.xphp" contains the following lines:
      """
      <?php
      namespace App\Chain;
      final class Widget
      {
          public function spin(): ?Widget { return null; }
      }
      """
    And the file at "ChainUse.xphp" contains the following lines:
      """
      <?php
      namespace App;
      use App\Chain\Bag;
      use App\Chain\Widget;
      $bag = new Bag::<Widget>();
      $w = $bag->first()?->spin();
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/definition" on "spin" at line 5 of "ChainUse.xphp"
    Then the response points to "Chain/Widget.xphp"
    And the target range covers the "spin" method declaration
