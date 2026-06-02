Feature: Workspace symbol search
  As a developer editing xphp
  I want to find symbols project-wide by a substring of their short name

  Background:
    Given the file at "/Tag.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Tag {}
      """
    And the file at "/Pair.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Pair {}
      """
    And the file at "/Repository.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Repository {}
      """
    And the FQN index has been warmed on initialize

  Scenario: Filter symbols by a case-insensitive short-name substring
    When I search workspace symbols for "tag"
    Then the workspace symbols include "Tag"
    And the workspace symbols exclude "Pair"
    And the workspace symbols exclude "Repository"
