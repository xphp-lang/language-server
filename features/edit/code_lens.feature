Feature: Code lens
  As a developer editing xphp
  I want a "Show references" lens above declarations, resolved lazily

  Background:
    Given the file at "/Foo.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Foo {
          public function bar(): void {}
      }
      """
    And the file at "/use.xphp" contains the following lines:
      """
      <?php
      use App\Foo;
      $f = new Foo();
      $f->bar();
      """
    And the FQN index has been warmed on initialize

  Scenario: Emit a "Show references" lens for a declaration
    When I request code lenses for "/Foo.xphp"
    Then a code lens titled "Show references" is offered

  Scenario: Resolve a lens to a usage count
    When I request code lenses for "/Foo.xphp"
    And I resolve the first code lens
    Then the resolved lens reads "2 usages"
    And the resolved lens carries the reference locations

  Scenario: A constructor lens counts "new" instantiation sites
    Given the file at "/Gadget.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Gadget {
          public function __construct() {}
      }
      """
    And the file at "/GadgetUse.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $a = new Gadget();
      $b = new Gadget();
      """
    When I request code lenses for "/Gadget.xphp"
    And I resolve the code lens on line 3
    Then the resolved lens reads "2 usages"
