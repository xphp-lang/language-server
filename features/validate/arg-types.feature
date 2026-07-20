Feature: Argument-type checking across call shapes
  As a developer editing xphp
  I want a would-be runtime TypeError surfaced as a diagnostic for every
  call shape -- not just constructors

  Scenario: Flag a scalar literal passed to an instance method
    Given the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User
      {
          public function rename(string $name): void {}
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $u = new User();
      $u->rename(42);
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.arg-mismatch" diagnostic is reported saying "expects string, got int"
    And the "xphp.arg-mismatch" diagnostic underlines "42"

  Scenario: Flag a scalar literal passed to a static method
    Given the file at "/Factory.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class Factory
      {
          public static function make(string $key): void {}
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      Factory::make(42);
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.arg-mismatch" diagnostic is reported saying "App\Factory::make()"

  Scenario: Flag a scalar literal passed to a free function
    Given the file at "/greet.xphp" contains the following lines:
      """
      <?php
      namespace App;
      function greet(string $name): void {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      greet(42);
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.arg-mismatch" diagnostic is reported saying "expects string, got int"

  Scenario: Flag a mismatched argument on a generic method, substituting the type-arg
    Given the file at "/Collection.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Collection<T>
      {
          public function add(T $item): void {}
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User {}
      """
    And the file at "/Tag.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class Tag {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $c = new Collection::<User>();
      $c->add(new Tag());
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.arg-mismatch" diagnostic is reported saying "App\User"
    And the "xphp.arg-mismatch" diagnostic underlines "new Tag()"

  Scenario: Flag a locally-assigned variable whose type can't satisfy the parameter
    Given the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User
      {
          public function rename(string $name): void {}
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $u = new User();
      $n = 42;
      $u->rename($n);
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.arg-mismatch" diagnostic is reported saying "expects string, got int"
    And the "xphp.arg-mismatch" diagnostic underlines "$n"

  Scenario: Accept a correct instance-method call without complaint
    Given the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User
      {
          public function rename(string $name): void {}
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $u = new User();
      $u->rename('alice');
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then no diagnostics are reported

  Scenario: An omitted default type argument substitutes into parameter checks
    Given the file at "/Box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T = User>
      {
          public function add(T $item): void {}
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User {}
      """
    And the file at "/Tag.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class Tag {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $b = new Box::<>();
      $b->add(new Tag());
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.arg-mismatch" diagnostic is reported saying "expects App\User, got App\Tag"

  Scenario: Omitting a default type argument is not a missing-type-arg error
    Given the file at "/Box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T = User>
      {
          public function add(T $item): void {}
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $b = new Box::<>();
      $b->add(new User());
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then no diagnostics are reported

  Scenario: An instance-method turbofish binds the type argument for checking
    Given the file at "/Holder.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Holder {
          public function add<T>(T $item): void {}
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User {}
      """
    And the file at "/Tag.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class Tag {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $h = new Holder();
      $h->add::<User>(new Tag());
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.arg-mismatch" diagnostic is reported saying "expects App\User, got App\Tag"

  Scenario: Supplying too many type arguments is not a false argument mismatch
    Given the file at "/Box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T>
      {
          public function add(T $item): void {}
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User {}
      """
    And the file at "/Tag.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class Tag {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $b = new Box::<User, Tag>();
      $b->add(new User());
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.too_many_type_arguments" diagnostic is reported saying "remove the extra"
