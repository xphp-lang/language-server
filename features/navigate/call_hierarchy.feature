Feature: Call hierarchy
  As a developer editing xphp
  I want to walk the incoming and outgoing calls of a method

  Background:
    Given the file at "/Repository.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Repository {
          public function save(): void {}
      }
      """
    And the file at "/persist.xphp" contains the following lines:
      """
      <?php
      namespace App;
      function persist(Repository $r): void {
          $r->save();
      }
      """
    And the file at "/Service.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Service {
          public function run(Repository $r): void {
              $r->save();
          }
      }
      """
    And the FQN index has been warmed on initialize

  Scenario: Prepare the call-hierarchy item at a method
    When I prepare call hierarchy on "save" at line 3 of "/Repository.xphp"
    Then the prepared item is named "App\Repository::save"

  Scenario: Find incoming calls to a method
    When I prepare call hierarchy on "save" at line 3 of "/Repository.xphp"
    And I request incoming calls
    Then an incoming call comes from "persist"

  Scenario: Find outgoing calls from a method body
    When I prepare call hierarchy on "run" at line 3 of "/Service.xphp"
    And I request outgoing calls
    Then an outgoing call goes to "save"
