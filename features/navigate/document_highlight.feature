Feature: Document highlight
  As a developer editing xphp
  I want every occurrence of the symbol under the cursor highlighted in the file

  Background:
    Given the file at "/Use.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class User {}
      $a = new User();
      $b = new User();
      """
    And the FQN index has been warmed on initialize

  Scenario: Highlight the declaration and both usages in the current file
    When I request "textDocument/documentHighlight" on "User" at line 2 of "/Use.xphp"
    Then the response contains 3 highlights
    And each highlight covers "User" in "/Use.xphp"
    And every document highlight range is within the bounds of "/Use.xphp"

  Scenario: Classify the declaration as a write and the uses as reads
    When I request "textDocument/documentHighlight" on "User" at line 2 of "/Use.xphp"
    Then a "write" highlight covers "User" in "/Use.xphp"
    And a "read" highlight covers "User" in "/Use.xphp"
