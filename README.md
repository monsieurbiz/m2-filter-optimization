# Magento 2 filter optimization

## Introduction

List every available filterable attributes depending on products inside a category and save it in cache.  
This avoids to load every filterable attribute even if it is not used in a category.

## Installation

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/monsieurbiz/m2-filter-optimization.git"
        }
    ],
    "require": {
        "monsieurbiz/m2-filter-optimization": "dev-master"
    }
}
```
---
*Made with :heart:*