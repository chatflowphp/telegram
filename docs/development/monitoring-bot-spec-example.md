# Example Spec: Monitoring Statistics Bot

This is a documentation-only example of a production-level `BOT_SPEC.md`.

It is not an implemented package example. It demonstrates how to convert a complex Telegram bot idea into a structured specification that can be implemented with `chatflowphp/telegram`.

## 1. Product Goal

Bot name: Monitoring Statistics Bot.

Primary goal: help advertisers measure conversion from advertising post views to channel subscriptions within a fixed monitoring window after campaign start.

Business outcome:

- User creates a campaign with tracked channels and start time.
- Bot monitors relevant statistics for 15 minutes after campaign start.
- Bot calculates conversion per advertisement version.
- Bot provides campaign details and downloadable reports.

Non-goals for first release:

- No automatic creative generation.
- No ad marketplace.
- No cross-platform adapters.
- No migration from legacy bot code.

## 2. Telegram UX Model

Primary UX:

- Menu navigation with inline buttons.
- Dialog scene for campaign creation.
- Detail screens for campaign statistics.
- Notifications when campaign monitoring starts and completes.
- Report delivery as generated files.

Rendering policy:

- `/start` uses `reply()` to send the main menu.
- Navigation callbacks use `ack()` and `render()` to update the current screen.
- Reports use `reply()` or `TelegramPublisher` depending on whether the report must be sent to the current chat or another chat.
- Campaign start/completion notifications use `TelegramPublisher` if they are sent by a scheduler outside the current update.

## 3. Roles And Permissions

| Role | Can Do | Cannot Do | Identification |
| --- | --- | --- | --- |
| User | Create campaigns, view own campaigns, download own reports | View other users' campaigns | Telegram user id |
| Admin | View diagnostics, retry failed monitoring jobs | Change another user's campaign unless explicitly allowed | Config allow-list |

Permission failures:

| Case | User Message | Log Context |
| --- | --- | --- |
| User opens another user's campaign | `Campaign not found.` | requested campaign id, requester user id |
| User starts monitoring without channel access | `The bot cannot access one or more channels.` | channel usernames, user id |

## 4. Entities

| Entity | Fields | Created By | Updated By | Persistence |
| --- | --- | --- | --- | --- |
| Campaign | `id`, `ownerUserId`, `name`, `status`, `startAt`, `monitoringWindowMinutes` | Create campaign scene | Scheduler, monitor service | Database |
| TrackedChannel | `id`, `campaignId`, `username`, `telegramChatId`, `status` | Create campaign scene | Channel validation service | Database |
| AdVersion | `id`, `campaignId`, `label`, `messageText`, `views`, `subscriptions`, `conversionRate` | Monitor service or import | Monitor service | Database |
| CampaignSnapshot | `id`, `campaignId`, `capturedAt`, `views`, `subscriptions` | Monitor service | Monitor service | Database |
| ReportFile | `id`, `campaignId`, `path`, `createdAt` | Report service | Report service | Filesystem or object storage |

Session keys:

| Key | Type | Owner | Cleared When |
| --- | --- | --- | --- |
| `campaign_draft.name` | string | `CreateCampaignScene` | Campaign is created or canceled |
| `campaign_draft.channels` | list of strings | `CreateCampaignScene` | Campaign is created or canceled |
| `campaign_draft.start_at` | string ISO datetime | `CreateCampaignScene` | Campaign is created or canceled |

## 5. Screen Map

| Screen Id | Text/Purpose | Actions | Render Mode | Handler |
| --- | --- | --- | --- | --- |
| `main` | Main menu | `campaign:create`, `campaigns:list`, `help:show` | `reply` from `/start`, `render` from back buttons | `MainMenuHandler` |
| `campaigns` | User campaigns menu | `campaigns:active`, `campaigns:finished`, `campaigns:report:download`, `main:open` | `render` | `CampaignMenuHandler` |
| `campaigns.active` | Active campaigns list | `campaign:view`, `campaigns:active:refresh`, `campaigns:list` | `render` | `CampaignListHandler` |
| `campaigns.finished` | Finished campaigns list | `campaign:view`, `campaigns:list` | `render` | `CampaignListHandler` |
| `campaign.detail` | Campaign status and current statistics | `campaign:refresh`, `campaign:report:download`, `campaigns:active` or `campaigns:finished` | `render` | `CampaignDetailHandler` |
| `help` | Short usage instructions | `main:open` | `render` | `HelpHandler` |

Screen text requirements:

- Every screen shows a breadcrumb-like title.
- Campaign detail screen shows start time, tracked channels, campaign status and statistics per ad version.
- Empty lists show a friendly empty state and a back button.

## 6. Action Catalog

| Action Id | Payload | Source Screen | Handler | Ack | Result |
| --- | --- | --- | --- | --- | --- |
| `main:open` | `null` | any menu screen | `showMainMenu` | none | Render main menu |
| `help:show` | `null` | main | `showHelp` | none | Render help screen |
| `campaign:create` | `null` | main | `enterCreateCampaign` | `Starting campaign setup` | Enter `CreateCampaignScene` |
| `campaigns:list` | `null` | main, child screens | `showCampaignsMenu` | none | Render campaigns menu |
| `campaigns:active` | `null` | campaigns menu | `showActiveCampaigns` | none | Render active list |
| `campaigns:active:refresh` | `null` | active list | `showActiveCampaigns` | `List refreshed` | Render active list |
| `campaigns:finished` | `null` | campaigns menu | `showFinishedCampaigns` | none | Render finished list |
| `campaign:view` | `{ "id": 123 }` | campaign lists | `showCampaignDetail` | none | Render detail screen |
| `campaign:refresh` | `{ "id": 123 }` | campaign detail | `refreshCampaignDetail` | `Statistics refreshed` | Render detail screen |
| `campaign:report:download` | `{ "id": 123 }` or `null` | campaign detail, campaigns menu | `downloadReport` | `Preparing report` | Send report file |

Payloads must contain scalar ids only.

## 7. Commands And Text Routes

| Input | Handler | Result |
| --- | --- | --- |
| `/start` | `showMainMenu` | Reply with main menu |
| `/help` | `showHelp` | Reply or render help |
| unmatched text outside scene | fallback | Tell user to use `/start` |

Fallback policy:

- Outside scenes, unmatched text replies: `I did not understand this message. Use /start to open the menu.`
- Inside scenes, scene validation owns the response.

## 8. Scenes And Dialogs

### CreateCampaignScene

Entry action: `campaign:create`.

Exit behavior:

- On success, create campaign and render campaign detail screen.
- On cancel, clear draft session keys and render main menu.

Back/cancel behavior:

- `campaign:create:cancel` cancels the scene.
- Back from the first step returns to main menu.

Session keys:

- `campaign_draft.name`
- `campaign_draft.channels`
- `campaign_draft.start_at`

| Step | Prompt | Expected Input | Validation | Error Message | Stores | Next |
| --- | --- | --- | --- | --- | --- | --- |
| `name` | `Enter campaign name.` | text | required, unique for user | `This campaign name is already used.` | `campaign_draft.name` | `channels` |
| `channels` | `Enter tracked channel usernames separated by commas, spaces or new lines.` | text list | every item is a channel username, bot can access channel | `Some channels could not be added. Check usernames and bot access.` | `campaign_draft.channels` | `start_time` |
| `start_time` | `Enter campaign start time in HH:MM format.` | text | valid time, not in the past for user's timezone | `Start time is invalid or already in the past.` | `campaign_draft.start_at` | `confirm` |
| `confirm` | Show campaign summary and ask confirmation | action | confirm/cancel | none | none | finish |

Scene actions:

| Action | Method | Payload | Result |
| --- | --- | --- | --- |
| Confirm | `onConfirm` | `null` | Create campaign |
| Cancel | `onCancel` | `null` | Clear draft and leave |

Channel validation result:

- If all channels are valid, reply only with success summary.
- If all channels fail, reply only with failure summary.
- If results are mixed, reply with both success and failure summaries.

## 9. Telegram-Specific Features

| Feature | Required | ChatFlow Tool | Notes |
| --- | --- | --- | --- |
| Channel access validation | yes | `Telegram\Bot\Api` inside infrastructure service | Needed to validate bot access to channels |
| Scheduled campaign notifications | yes | `TelegramPublisher` | Scheduler has no current update context |
| Report delivery | yes | `TelegramPublisher` or `Context::reply()` with Telegram options | Depends on whether delivery is current-chat or scheduled |
| Membership updates | maybe | `onTelegramEvent('chat_member')` | Only if Telegram configuration can provide reliable member updates |
| File generation | yes | business report service | Not a Telegram-specific concern |

Important Telegram API constraint:

Bots may not be able to observe every channel subscription event unless the bot has the required Telegram permissions and update types, or an external analytics/statistics source is used. The implementation must validate this before promising exact subscription tracking.

## 10. Integrations

| Integration | Purpose | Success | Failure | Retry |
| --- | --- | --- | --- | --- |
| Telegram Bot API | validate channels, send notifications, deliver reports | response contains Telegram ids where needed | user-facing access error or logged delivery failure | yes for scheduled jobs |
| Persistent database | store campaigns, channels, snapshots, reports | transaction committed | log and show retry message | depends on operation |
| Scheduler/queue | start and complete monitoring windows | job executed at expected time | retry with backoff and admin log | yes |
| Report generator | create spreadsheet report | report file path stored | show `Report generation failed. Try again later.` | yes |
| Statistics provider | collect views/subscriptions | snapshot stored | mark snapshot failed and notify admin if repeated | yes |

## 11. Error Policy

| Error | User Message | Ack/Error Alert | Log Level | Recovery |
| --- | --- | --- | --- | --- |
| Duplicate campaign name | `This campaign name is already used.` | no | info | Ask name again |
| Invalid channel username | `Some channels could not be added. Check usernames and bot access.` | no | info | Ask channels again |
| Campaign not found | `Campaign not found.` | yes for callbacks | warning | Return to list |
| Report generation failed | `Report generation failed. Try again later.` | yes for callbacks | error | Retry action |
| Telegram delivery failed | `Telegram delivery failed. Try again later.` | yes for callbacks | error | Retry action |

Typed exceptions:

| Exception | Handler | User Result |
| --- | --- | --- |
| `CampaignNotFoundException` | `onException()` | Ack or reply `Campaign not found.` |
| `ChannelAccessException` | scene handler | Ask channels again |
| `ReportGenerationException` | `onException()` | Ack or reply retry message |

## 12. Observability

Runtime observer:

- Enable `JsonlRuntimeObserver` for local and staging environments.

Business logs:

- Campaign created.
- Monitoring started.
- Snapshot captured.
- Monitoring completed.
- Report generated.
- Notification delivered.

Sensitive data that must not be logged:

- Raw private user messages unless needed for validation diagnostics.
- API tokens.
- Generated report file contents.

Admin diagnostics:

- Failed monitoring jobs.
- Failed Telegram deliveries.
- Channels with invalid access.

## 13. Acceptance Scenarios

### Scenario: Start Bot

Given user is allowed.

When user sends `/start`.

Then bot replies with `Main menu`.

And bot shows buttons `Create campaign`, `My campaigns`, `Help`.

### Scenario: Create Campaign Successfully

Given user has no campaign named `June Promo`.

When user sends `/start`.

And clicks `campaign:create`.

Then bot asks `Enter campaign name.`

When user sends `June Promo`.

Then bot asks for tracked channel usernames.

When user sends `@channel_one, @channel_two`.

Then bot confirms accessible channels.

When user sends a valid future start time.

Then bot shows a confirmation screen.

When user confirms.

Then campaign is created.

And campaign detail screen is rendered.

### Scenario: Duplicate Campaign Name

Given user already has campaign `June Promo`.

When user enters `June Promo` in the name step.

Then bot replies `This campaign name is already used.`

And user stays in `CreateCampaignScene`.

### Scenario: Invalid Channel

Given Telegram channel validation fails for `@missing_channel`.

When user enters `@missing_channel`.

Then bot replies `Some channels could not be added. Check usernames and bot access.`

And user stays in the channels step.

### Scenario: View Active Campaign

Given user has active campaign `June Promo`.

When user opens `My campaigns`.

And clicks `Active campaigns`.

And opens campaign details.

Then bot renders campaign details with status and current statistics.

### Scenario: Download Campaign Report

Given report generation succeeds.

When user clicks `campaign:report:download`.

Then bot acknowledges `Preparing report`.

And sends a report file.

### Scenario: Campaign Completion Notification

Given campaign monitoring window has ended.

When scheduler completes the monitoring job.

Then bot sends a completion notification to the campaign owner.

And the notification contains views, subscriptions and conversion rate per ad version.

## 14. Implementation Blueprint

Implementation blueprint should define:

- `MonitoringBotFactory`
- `MonitoringFlow`
- `CreateCampaignScene`
- `CampaignService`
- `ChannelAccessService`
- `CampaignMonitoringService`
- `ReportService`
- `CampaignRepository`
- fake services for acceptance tests
- scheduler entrypoint for monitoring jobs

## 15. Open Questions

| Question | Owner | Required Before Coding |
| --- | --- | --- |
| Which source provides exact subscription events or subscription deltas? | Product/engineering | yes |
| Which timezone should campaign start time use? | Product | yes |
| Which spreadsheet format is required: XLSX or CSV? | Product | yes |
| Where should reports be stored and for how long? | Engineering | yes |
| Should admins see all user campaigns? | Product | no |
