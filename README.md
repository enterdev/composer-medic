# composer-medic
Composer plugin to install patches to vendor repositories

#Installation
1. composer require enterdev/composer-medic
2. add medic.lock to your .gitignore (or any other -ignore)
3. add the following to your composer.json: 
```
"extra": {
    "medic": {
        "<vendor/package>": {
            "<path_to_patch.patch>": "<description of the patch>"
        }
    }
}
```

You may have the patches in your main repository or in the dependencies, they will be installed after all the packages are installed.

If patches list is changed, the package will be reinstalled and all the patches applied again.
