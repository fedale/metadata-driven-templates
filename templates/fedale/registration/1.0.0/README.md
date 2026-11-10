# Registration

Users signing up: an **account** (username, email, name, password), its **profile** (gender,
birth date, where they live, postal code), the profile's **phones** and the account's
**qualifications**, all entered in one form, `registration`.

Where they live comes from fedale/geo-italy: the region, then a province of that region, then a
municipality of that province. Installing this template from the catalog installs
fedale/geo-italy first when the instance doesn't have it.

The form asks for the municipality, at least one phone, and the acceptance of the terms. Labels
are in English, with an Italian translation.
