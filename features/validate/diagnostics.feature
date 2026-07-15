Feature: Diagnostics
  As a developer editing xphp
  I want compile-time problems surfaced as diagnostics

  Scenario: A well-formed file reports nothing
    Given the file at "/Clean.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User
      {
          public function __construct(public string $name) {}
      }
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Clean.xphp" for diagnostics
    Then no diagnostics are reported

  Scenario: Report a syntax error
    Given the file at "/Broken.xphp" contains the following lines:
      """
      <?php $broken = "unterminated
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Broken.xphp" for diagnostics
    Then a "xphp.parse" diagnostic is reported saying "Syntax error"

  Scenario: An EOF-anchored syntax error stays within document bounds
    # Regression for the PhpStorm "Range must be inside element being annotated"
    # crash: an unterminated block comment yields an EOF-anchored error whose raw
    # end column lands one past EOL. The emitted range must be clamped.
    Given the file at "/Unterminated.xphp" contains the following lines:
      """
      <?php
      /* unterminated
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Unterminated.xphp" for diagnostics
    Then a "xphp.parse" diagnostic is reported saying "Syntax error"
    And every reported diagnostic range is within document bounds

  Scenario: Warn about an undefined bareword
    Given the file at "/Typo.xphp" contains the following lines:
      """
      <?php
      $x = 1 ?? nul;
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Typo.xphp" for diagnostics
    Then a "xphp.undefined-name" diagnostic is reported saying "nul"
    And the "xphp.undefined-name" diagnostic underlines "nul"

  Scenario: Report a duplicate template declaration on the edited file
    Given the file at "/BoxOne.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T> { public T $item; }
      """
    And the file at "/BoxTwo.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T> { public T $item; }
      """
    And the FQN index has been warmed on initialize
    When I analyze "/BoxTwo.xphp" for diagnostics
    Then a "xphp.definition" diagnostic is reported saying "already declared"
    And the "xphp.definition" diagnostic underlines "Box"

  Scenario: Report a generic bound violation
    Given the file at "/Box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T: \Stringable>
      {
          public T $item;
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Box::<int>();
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.bound" diagnostic is reported saying "Generic bound violated"
    And the "xphp.bound" diagnostic underlines "Box"

  Scenario: Report an empty turbofish on a template with no default
    Given the file at "/Box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T> {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Box::<>();
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.missing_type_argument" diagnostic is reported saying "no default"

  Scenario: Report a constructor argument-type mismatch
    Given the file at "/StringableBox.xphp" contains the following lines:
      """
      <?php
      namespace App\Containers;
      class StringableBox<T: \Stringable>
      {
          public function __construct(public T $item) {}
      }
      """
    And the file at "/Tag.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      final class Tag implements \Stringable {
          public function __toString(): string { return 'tag'; }
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      final class User {}
      """
    And the file at "/Bounds.xphp" contains the following lines:
      """
      <?php
      namespace App\Demos;
      use App\Containers\StringableBox;
      use App\Models\Tag;
      use App\Models\User;
      $v = new StringableBox::<Tag>(new User());
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Bounds.xphp" for diagnostics
    Then a "xphp.ctor-arg-mismatch" diagnostic is reported
    And the "xphp.ctor-arg-mismatch" diagnostic underlines "new User()"

  Scenario: Warn about a null dereference on a chained nullable receiver
    Given the file at "/Collection.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Collection<T>
      {
          public function first(): ?T { return null; }
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User { public string $name = ''; }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $users = new Collection::<User>();
      $name = $users->first()->name;
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then a "xphp.null-deref" diagnostic is reported saying "possibly-null"
    And the "xphp.null-deref" diagnostic underlines "name"
    And every reported diagnostic range is within document bounds

  Scenario: A nullsafe access on the same nullable chain is not flagged
    Given the file at "/Collection.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Collection<T>
      {
          public function first(): ?T { return null; }
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class User { public string $name = ''; }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $users = new Collection::<User>();
      $name = $users->first()?->name;
      """
    And the FQN index has been warmed on initialize
    When I analyze "/Use.xphp" for diagnostics
    Then no diagnostics are reported
