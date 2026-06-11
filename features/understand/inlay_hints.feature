Feature: Inlay hints
  As a developer editing xphp
  I want the concrete type a generic method resolved to shown after an assignment

  Scenario: Hint the substituted return type of a generic class method
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
      $users = new Collection::<User>();
      $first = $users->first();
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/inlayHint" for the visible range of "/Use.xphp"
    Then exactly 1 inlay hint is rendered
    And an inlay hint ": ?App\Models\User" is rendered after "$first" on line 4 of "/Use.xphp"

  Scenario: Hint a generic method turbofish called on a local-variable receiver
    Given the file at "/Util.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Util
      {
          public function identity<T>(T $x): T { return $x; }
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\Util;
      $u = new Util();
      $i = $u->identity::<int>(99);
      $s = $u->identity::<string>('world');
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/inlayHint" for the visible range of "/Use.xphp"
    Then exactly 2 inlay hints are rendered
    And an inlay hint ": int" is rendered after "$i" on line 3 of "/Use.xphp"
    And an inlay hint ": string" is rendered after "$s" on line 4 of "/Use.xphp"

  Scenario: Hint a static return type resolved to the receiver's concrete type
    Given the file at "/Builder.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Builder<T>
      {
          public function __construct(public T $value) {}
          public function fresh(T $v): static { return new static::<T>($v); }
      }
      """
    And the file at "/Use.xphp" contains the following lines:
      """
      <?php
      use App\Builder;
      $a = new Builder::<int>(1);
      $b = $a->fresh(2);
      """
    And the FQN index has been warmed on initialize
    When I request "textDocument/inlayHint" for the visible range of "/Use.xphp"
    Then exactly 1 inlay hint is rendered
    And an inlay hint ": App\Builder<int>" is rendered after "$b" on line 3 of "/Use.xphp"
