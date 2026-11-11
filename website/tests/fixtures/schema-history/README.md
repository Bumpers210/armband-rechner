# Historical SQL fixture (no runtime data)

`ap6-commerce-schema.sql` is the LF-normalized repository SQL from
`bb4345fbfb26fede5bdf61be0ef6191746a98ef0:website/database/commerce-schema.sql`.
It contains no production rows, credentials or database names.

Commit `b4c447a695e7aa13a3eb62a6ad78e0e23136bdb5` added the two
`sales_model` columns and `product_projection_operations` to the base file.
These additions also exist in the separate `commerce-v2-collections`
forward migration. The original AP6 journal entry must remain unchanged.

The Node test pins all three normalized SQL hashes and checks that there
are no other base-schema differences. The PHP test exercises the journal
guard and import with artificial SQLite in-memory data, including write
denial, tampering, rollback and replay. It is not a MySQL DDL claim.

The isolated MySQL harness executes this exact old DDL before AP4, AP5,
collections and cart, then compares the final schema to a fresh install and
checks that all original journal rows, including timestamps, are preserved.
The fixture is test-only and is never part of the deployment package.
