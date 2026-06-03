Feature: Quick-fixes for generic bound violations
  As a developer editing xphp
  I want lightbulb fixes when a type argument violates a generic bound

  Background:
    Given the file at "/Stringy.xphp" contains the following lines:
      """
      <?php
      namespace App;
      final class Stringy implements \Stringable {
          public function __toString(): string { return ''; }
      }
      """
    And the file at "/Box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T: \Stringable> { public T $item; }
      """
    And the FQN index has been warmed on initialize

  Scenario: Offer to swap a scalar type argument for a bound-satisfying type
    Given the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Box<int>();
      """
    When I request code actions for the "xphp.bound" diagnostic in "/Use.xphp"
    Then a code action titled "Change type argument to Stringy" is offered
    And the "Change type argument to Stringy" action has kind "quickfix"
    And the "Change type argument to Stringy" action replaces "int" with "Stringy"

  Scenario: Offer to implement the bound on the offending concrete class
    Given the file at "/Money.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Money {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Box<Money>();
      """
    When I request code actions for the "xphp.bound" diagnostic in "/Use.xphp"
    Then a code action titled "Add implements \Stringable to Money" is offered
    And the "Add implements \Stringable to Money" action inserts "implements \Stringable"
    And a code action titled "Change type argument to Stringy" is offered
