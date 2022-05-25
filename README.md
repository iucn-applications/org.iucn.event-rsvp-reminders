# org.iucn.event-rsvp-reminders

![Screenshot](/images/event_rsvp_reminders.gif)

## Description

Adds functionality to capture Event Invitations responses
* Custom fields to capture the participants response (modifiable)
* A token with a unique link for each posible response
* A url to capture the participant's reponse and update the fields
* Possibility to filter reminder by custom fields to allow to send reminders only to those who haven't answered

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Details

### RSVP Functionality

* Creates Custom Group **Event Invitation** for **Participants**
* Creates Custom Fields **Invitation Reply** and **Invitation Reply Date**
* Creates a URL to receive the invitation responses - *civicrm/eventrsvp*
* Generates tokens for each response which evaluate into an url to store said response.
* All fields inside **Event Invitation** can be modifiable. The way it works is:
  * All fields in **Event Invitation** that relate to an option group (Select/Radio/Checkbox) will be considered when generating the tokens
  * When storing the response for a field *<field_name>*, if the field *<field_name>**_date*** exists, the date will also be stored
* This also means that we can simply add more fields to track other invitation types. 
  * I.e.: let's say we want to store the replies to a *Save the Date* and to an actual Invitation. For that we only need to create two new fields: **Save the date reply** (type select) and **Save the date reply date** (type date, optional)



### Reminders' filters

In order for this functionality to work, we'll need to send Invitations / Reminders to participants, but we don't want to bother those who have answered already.
As such this extension extends the Schedule Reminder functionality to allow to filter reminders by Custom fields:
* When setting a reminder for an event, select **Limit to** and the available Custom fields with options will appear in the list
* Simply select the value that you want to receive the Reminder (limit to)

Note: Only works for **Limit To**. Does NOT work for **Also Include**.

## Requirements

* PHP v7.2+
* CiviCRM 5.35

## Installation (Web UI)

Learn more about installing CiviCRM extensions in the [CiviCRM Sysadmin Guide](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/).

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl org.iucn.event-rsvp-reminders@https://github.com/FIXME/org.iucn.event-rsvp-reminders/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/org.iucn.event-rsvp-reminders.git
cv en event_rsvp_reminders
```

## Getting Started

Works out of the box.
Just send use the Event Invitation tokens when sending reminders.


## Known Issues

* There is no security mechanism before saving the responses: each time a user clicks on one of the links, the response will be store, overriding whatever was there before

## Todos

* Add configuration for "Thank You" text
