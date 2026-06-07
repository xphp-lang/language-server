Feature: Cross-file diagnostic broadcast
  As a developer editing xphp
  I want a dependent file's diagnostics to refresh when I edit a file it
  depends on -- without having to open or touch the dependent myself

  Scenario: Adding a bound to a template re-flags its open dependents
    Given the file at "/Box.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Box<T> { public T $item; }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = new Box::<int>();
      """
    And the FQN index has been warmed on initialize
    And the diagnostics service is running
    When I change the file at "/Box.xphp" to contain the following lines:
      """
      <?php
      namespace App;
      class Box<T: \Stringable> { public T $item; }
      """
    Then a "xphp.bound" diagnostic is published for "/Use.xphp" without re-requesting it
