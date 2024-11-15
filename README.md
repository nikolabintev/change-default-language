# Change Default Language
___
An experimental module that provides a drush command to change the default language of the website with an already existing content.

It is clearly stated in the [Drupal documentation](https://www.drupal.org/docs/administering-a-drupal-site/multilingual-guide/install-a-language) <strong>never to change the
default language</strong>, however, it is sometimes necessary to do so.


## Usage
To set a new default language, use the following Drush command:
```bash
drush language:set:default <langcode> <name> [<direction>]
```
- langcode: The language code (e.g., en-US).
- name: The language name (e.g., English (United States)).
- direction (optional): The language direction (ltr or rtl). Defaults to ltr.

### Example
```bash
drush language:set:default en-US "English (United States)"
```

## Todo
- Redirects.
- Paragraphs.

## Known issues
- The URL aliases are deleted.


