Feature: Inlay hints
  As a developer editing xphp
  I want the concrete type a generic method resolved to shown after an assignment

  Background:
    Given the file at "/Collection.xphp" contains the following lines:
      """
      <?php
      namespace App\Containers;
      class Collection<T>
      {
          public function first(): ?T { return null; }
      }
      """
    And the file at "/User.xphp" contains the following lines:
      """
      <?php
      namespace App\Models;
      class User {}
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\Containers\Collection;
      use App\Models\User;
      $users = new Collection<User>();
      $first = $users->first();
      """
    And the FQN index has been warmed on initialize

  Scenario: Hint the substituted return type of a generic method call
    When I request "textDocument/inlayHint" for the visible range of "/Use.xphp"
    Then exactly 1 inlay hint is rendered
    And an inlay hint ": ?App\Models\User" is rendered after "$first" on line 4 of "/Use.xphp"
