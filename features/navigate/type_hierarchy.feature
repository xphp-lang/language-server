Feature: Type hierarchy
  As a developer editing xphp
  I want to walk the supertypes and subtypes of a class or interface

  Background:
    Given the file at "/Animal.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Animal {}
      """
    And the file at "/Dog.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Dog extends Animal {}
      """
    And the file at "/Speaker.xphp" contains the following lines:
      """
      <?php
      namespace App;
      interface Speaker {}
      """
    And the file at "/Cat.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Cat implements Speaker {}
      """
    And the FQN index has been warmed on initialize

  Scenario: Prepare the type-hierarchy item at a class
    When I prepare type hierarchy on "Dog" at line 2 of "/Dog.xphp"
    Then the prepared item is named "Dog"

  Scenario: Walk supertypes to the parent class
    When I prepare type hierarchy on "Dog" at line 2 of "/Dog.xphp"
    And I request supertypes
    Then a supertype is named "Animal"
    And a supertype "Animal" has fqn "App\Animal"

  Scenario: Walk subtypes to the implementers of an interface
    When I prepare type hierarchy on "Speaker" at line 2 of "/Speaker.xphp"
    And I request subtypes
    Then a subtype is named "Cat"
    And a subtype "Cat" has fqn "App\Cat"
