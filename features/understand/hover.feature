Feature: Hover
  As a developer editing xphp
  I want hover to explain generic instantiations and type parameters

  Scenario: Hover over a generic instantiation shows the specialized type
    Given the file at "/doc.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Box::<Plastic>();
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "Box" at line 2 of "/doc.xphp"
    Then the hover contents contain "Specializes to:"
    And the hover contents contain "XPHP\Generated\App\Box\"

  Scenario: Hover over a type parameter explains it and its bound
    Given the file at "/box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T: \Stringable>
      {
          public T $item;
      }
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "T" at line 4 of "/box.xphp"
    Then the hover contents contain "Type parameter"
    And the hover contents contain "`T`"
    And the hover contents contain "App\Box"
    And the hover contents contain "Stringable"

  Scenario: Hover over a type parameter shows a composite bound
    Given the file at "/pair.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Pair<T : Animal & Comparable>
      {
          public T $item;
      }
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "T" at line 4 of "/pair.xphp"
    Then the hover contents contain "bounded by"
    And the hover contents contain "\App\Animal & \App\Comparable"

  Scenario: Hover over a covariant type parameter shows its variance
    Given the file at "/producer.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Producer<+T>
      {
          public function get(): T {}
      }
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "T" at line 4 of "/producer.xphp"
    Then the hover contents contain "`+T`"
    And the hover contents contain "covariant"

  Scenario: Hover over a generic method turbofish call shows the specialized signature
    Given the file at "/Util.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Util
      {
          public function identity<T>(T $x): T { return $x; }
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\Util;
      $u = new Util();
      $s = $u->identity::<string>('world');
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "identity" at line 3 of "/Use.xphp"
    Then the hover contents contain "identity(string $x): string"

  Scenario: Hover over a method returning static resolves to the receiver's concrete type
    Given the file at "/Builder.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Builder<T>
      {
          public function __construct(public T $value) {}
          public function fresh(T $v): static { return new static::<T>($v); }
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\Builder;
      $a = new Builder::<int>(1);
      $b = $a->fresh(2);
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "fresh" at line 3 of "/Use.xphp"
    Then the hover contents contain "fresh(int $v): App\Builder<int>"

  Scenario: Hover over a generic property substitutes the type parameter
    Given the file at "/Plastic.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Plastic {}
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class User {}
      """
    And the file at "/Pair.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Pair<A, B>
      {
          public function __construct(public A $first, public B $second) {}
          public function swap(): Pair<B, A> { return new Pair::<B, A>($this->second, $this->first); }
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\Pair;
      use App\Plastic;
      use App\User;
      $pair = new Pair::<Plastic, User>(new Plastic(), new User());
      echo $pair->first;
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "first" at line 5 of "/Use.xphp"
    Then the hover contents contain "App\Plastic $first"

  Scenario: Hover over a property through a chained generic method call substitutes the type parameter
    Given the file at "/Plastic.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Plastic {}
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class User {}
      """
    And the file at "/Map.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Map<K, V> {}
      """
    And the file at "/Pair.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Pair<A, B>
      {
          public function __construct(public A $first, public B $second) {}
          public function swap(): Pair<B, A> { return new Pair::<B, A>($this->second, $this->first); }
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\Map;
      use App\Pair;
      use App\Plastic;
      use App\User;
      $nested = new Pair::<Map<string, int>, Pair<Plastic, User>>(new Map(), new Pair::<Plastic, User>(new Plastic(), new User()));
      echo $nested->swap()->first;
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "first" at line 6 of "/Use.xphp"
    Then the hover contents contain "Pair<App\Plastic, App\User> $first"

  Scenario: A nullsafe property access on a nullable generic result is itself nullable
    Given the file at "/Collection.xphp" contains the following lines:
      """
      <?php
      namespace App\Containers;
      class Collection<T>
      {
          public function first(): ?T { return null; }
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      class User { public string $name = ''; }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\Containers\Collection;
      use App\Models\User;
      $users = new Collection::<User>();
      $firstName = $users->first()?->name;
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "firstName" at line 4 of "/Use.xphp"
    Then the hover contents contain "?string $firstName"

  Scenario: A multi-hop nullsafe property chain through a nullable generic result is nullable
    Given the file at "/Collection.xphp" contains the following lines:
      """
      <?php
      namespace App\Containers;
      class Collection<T>
      {
          public function first(): ?T { return null; }
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      class User
      {
          public string $name = '';
          public ?User $bestFriend = null;
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\Containers\Collection;
      use App\Models\User;
      $users = new Collection::<User>();
      $friendName = $users->first()?->bestFriend?->name;
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "friendName" at line 4 of "/Use.xphp"
    Then the hover contents contain "?string $friendName"

  Scenario: A chain through a non-generic method resolves
    Given the file at "/Collection.xphp" contains the following lines:
      """
      <?php
      namespace App\Containers;
      class Collection<T>
      {
          public function first(): ?T { return null; }
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      class User
      {
          public string $name = '';
          public function mirror(): ?User { return null; }
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\Containers\Collection;
      use App\Models\User;
      $users = new Collection::<User>();
      $picked = $users->first()?->mirror()?->name;
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "picked" at line 4 of "/Use.xphp"
    Then the hover contents contain "?string $picked"
