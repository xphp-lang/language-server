Feature: Go to implementation
  As a developer editing xphp
  I want "Go to Implementation" to list the classes that implement an interface

  Background:
    Given the file at "/Speaker.xphp" contains the following lines:
      """
      <?php
      namespace App;
      interface Speaker {}
      """
    And the file at "/Dog.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Dog implements Speaker {}
      """
    And the file at "/Cat.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Cat implements Speaker {}
      """
    And the FQN index has been warmed on initialize

  Scenario: List the implementers of an interface
    When I request "textDocument/implementation" on "Speaker" at line 2 of "/Speaker.xphp"
    Then the response contains 2 locations
    And a reference in "/Dog.xphp" covers "Dog"
    And a reference in "/Cat.xphp" covers "Cat"
