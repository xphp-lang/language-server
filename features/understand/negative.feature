Feature: Understanding when there is nothing to explain
  As a developer editing xphp
  I want hover/signature/inlay to stay quiet where there is nothing to show

  Scenario: Hover over a literal yields nothing
    Given the file at "/doc.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = 1;
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/hover" on "1" at line 2 of "/doc.xphp"
    Then there is no hover

  Scenario: No inlay hints without a generic assignment
    Given the file at "/doc.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = 1;
      $y = 'a';
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/inlayHint" for the visible range of "/doc.xphp"
    Then exactly 0 inlay hints are rendered

  Scenario: No signature help outside a call
    Given the file at "/doc.xphp" contains the following lines:
      """
      <?php
      namespace App;
      $x = 1;
      """
    And the FQN index has been warmed on initialize
    When I request signature help after "$x" at line 2 of "/doc.xphp"
    Then the response is null
