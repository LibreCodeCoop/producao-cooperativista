```mermaid
classDiagram
    namespace AkauntingDocument {
        class Document {
            <<abstract>>
            Cooperado cooperado
            Database db
            Dates dates
            Invoices invoices
        }
        class FRRA
        class ProducaoCooperativista
    }

    namespace Taxes {
        class Tax
        class Irpf
        class InssIrpf
        class Pis
        class Iss
        class Cofins
    }

    namespace Source {
        class Customers
        class Invoices
        %% class Nfse
        %% class Projects
        %% class Timesheet
        %% class Transactions
        %% class Users
    }

    namespace Provider {
        class Akaunt {
            <<trait>>
        }
        class Kimai {
            <<trait>>
        }
    }

    namespace Helper {
        class Dates
        class MagicGetterSetterTrait
    }

    namespace Service {
        class Cooperado
    }

    Document --* Akaunt

    ProducaoCooperativista --|> Document

    FRRA --|> Document

    Tax --|> Document
    Irpf --|> Tax
    InssIrpf --|> Irpf
    Pis --|> Tax
    Iss --|> Tax
    Cofins --|> Tax
```