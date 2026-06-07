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
