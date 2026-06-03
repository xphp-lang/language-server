Feature: Rename class on file rename
  As a developer editing xphp
  I want renaming a file to rename its single class and update references

  Background:
    Given the file at "file:///Collection.xphp" contains the following lines:
      """
      <?php
      namespace App;
      class Collection {}
      """
    And the file at "file:///Consumer.xphp" contains the following lines:
      """
      <?php
      namespace App\X;
      use App\Collection;
      $c = new Collection();
      """
    And the FQN index has been warmed on initialize

  Scenario: Renaming the file renames the class and updates the importer
    When I rename the file "file:///Collection.xphp" to "file:///Zollection.xphp"
    Then the rename touches 2 files
    And a willRename edit inserts "Zollection"
