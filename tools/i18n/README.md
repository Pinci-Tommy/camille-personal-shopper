# Plugin translations

Development tools only: they are not part of the plugin zip.

From this folder, with Python 3:

```
python3 extract.py ../.. strings.json        # reads the strings of the plugin
python3 build_i18n.py ../..                  # writes languages/*.pot, .po, .mo and the JS .json files
```

`translations.py` holds the Italian, French, German and Spanish texts, in the informal register
(tu, du, tú; French keeps vous), with the glossary at the top. A new string in the code needs its four
translations here, otherwise `build_i18n.py` reports it.
