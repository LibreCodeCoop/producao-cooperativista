```mermaid
classDiagram
    namespace Providers {
        class Akaunting
        class Kimai
    }

    namespace Akaunting {
        class ParseText
        class FetchList
        class Request
    }

    FetchList --|> Request
```