#!/bin/bash

FIRST_TAG=0

TAGS=()

### CHECK TAG BASE EXISTANCE
RESPONSE=$(curl --write-out '%{url_effective} [%{response_code}]' --silent --output /dev/null --header "PRIVATE-TOKEN: $TOKEN_GITLAB" "https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/repository/tags?search=^$TAG_BASE")
RESPONSE_CODE=$(echo $RESPONSE | grep -o '\[[0-9]*\]$')

if [ $RESPONSE_CODE != '[200]' ]; then
    echo "Error ! $RESPONSE"
    exit 1
fi
###

### CHECK IF THIS MERGE REQUEST IS MERGEABLE
MR_ID=`echo $CI_OPEN_MERGE_REQUESTS | grep -oP "!(.)*" | tr -d "!"`

if [ $MR_ID == '' ]; then
    echo "MR ID invalid ! $MR_ID"
    exit 1
fi

content=$(curl --header "PRIVATE-TOKEN: $TOKEN_GITLAB" "https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/merge_requests/$MR_ID")
STATUS=$(jq -r '.detailed_merge_status' <<<"$content")

echo $STATUS

if [ $STATUS != 'mergeable' ]; then
    echo "2301_releases cannot be merge ! Tag Abort"
    exit 1
fi
###

### RETRIEVE PUBLIC TAGS
for row in $(curl --header "PRIVATE-TOKEN: $TOKEN_GITLAB" "https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/repository/tags?search=^$TAG_BASE" | jq -r '.[] | @base64'); do
    _jq() {
        echo ${row} | base64 --decode | jq -r ${1}
    }

    NAME=$(_jq '.name')

    IS_TMA=$(echo $NAME | grep -o '[.]*_TMA[.]*')

    if [[ -n $IS_TMA ]]; then
        echo "TMA tag branch : $NAME ! Skipping..."
    else
        TAGS+=("$NAME")
    fi
done
###

### RETRIEVE LAST TAG
if [ ${#TAGS[@]} -eq 0 ]; then
    echo "No Tags available, create de first tag !"
    FIRST_TAG=1
    LATEST_TAG="$TAG_BASE.0.0"
    BRANCH_TAG_VERSION=$TAG_BASE
    MAJOR_TAG_VERSION='0'
    MINOR_TAG_VERSION='0'
    NEXT_TAG="$TAG_BASE.0.0"
else
    SORTED_TAGS=($(echo ${TAGS[*]} | tr " " "\n" | sort -Vr))
    LATEST_TAG=$(echo ${SORTED_TAGS[0]})

    echo "Latest tag : $LATEST_TAG"

    structures=$(echo $LATEST_TAG | tr "." "\n")

    IT=1
    for item in $structures; do
        if [ $IT = 1 ]; then
            BRANCH_TAG_VERSION=$item
        fi

        if [ $IT = 2 ]; then
            MAJOR_TAG_VERSION="$item"
        fi

        if [ $IT = 3 ]; then
            MINOR_TAG_VERSION=$item
        fi

        IT=$((IT + 1))
    done
fi

echo "BRANCH : $BRANCH_TAG_VERSION"
echo "MAJOR TAG : $MAJOR_TAG_VERSION"
echo "MINOR TAG : $MINOR_TAG_VERSION"
###


if [[ -z $BRANCH_TAG_VERSION ]] || [[ -z $MAJOR_TAG_VERSION ]] || [[ -z $MINOR_TAG_VERSION ]]; then
    echo "Bad tag structure ! $LATEST_TAG"
    exit 1
fi

### RETRIEVE NEXT TAG TO CREATE
if [ $FIRST_TAG == 0 ]; then
    VERSION=$((MINOR_TAG_VERSION + 1))
    VERSION_NEXT=$((MINOR_TAG_VERSION + 2))
    NEXT_TAG="$BRANCH_TAG_VERSION.$MAJOR_TAG_VERSION.$VERSION"
    NEXT_NEXT_TAG="$BRANCH_TAG_VERSION.$MAJOR_TAG_VERSION.$VERSION_NEXT"

    echo "NEXT TAG : $NEXT_TAG"
fi
###

### CREATE TEMPORARY BRANCH TO BUILD DEPENDENCIES WITH APPLICATION
curl --request POST --header "PRIVATE-TOKEN: $TOKEN_GITLAB" "https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/repository/branches?branch=tmp_$RELEASE_BRANCH&ref=$RELEASE_BRANCH"

git config --global user.email "$GITLAB_USER_EMAIL" && git config --global user.name "$GITLAB_USER_NAME" && git config core.fileMode false
git remote set-url origin "https://gitlab-ci-token:${TOKEN_GITLAB}@${GITLAB_URL}/${CI_PROJECT_PATH}.git"
git fetch
git branch -D tmp_$RELEASE_BRANCH
git pull origin tmp_$RELEASE_BRANCH
git checkout tmp_$RELEASE_BRANCH

composer install
npm run reload-packages
npm run build-prod

npm run reload-packages-addin-outlook
npm run build-prod-addin-outlook

npm run reload-packages-prod-addin-outlook
npm run reload-packages-prod

git add -f dist/
git add -f dist-addin/
git add -f node_modules/
git add -f vendor/

git commit -m "Add packages dependencies"

git push
###

### CREATE TAG
curl -w " => %{url_effective} [%{response_code}]" -H 'Content-Type:application/json' -H "PRIVATE-TOKEN:$TOKEN_GITLAB" -X POST "https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/repository/tags?tag_name=$NEXT_TAG&ref=tmp_$RELEASE_BRANCH"
###

curl --request DELETE --header "PRIVATE-TOKEN: $TOKEN_GITLAB" "https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/repository/branches/tmp_$RELEASE_BRANCH"

if [ $FIRST_TAG == 0 ]; then
    ### CLOSE MILESTONE
    for row in $(curl --header "PRIVATE-TOKEN: $TOKEN_GITLAB" "https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/milestones?search=$NEXT_TAG" | jq -r '.[] | @base64'); do
        _jq() {
            echo ${row} | base64 --decode | jq -r ${1}
        }

        ID=$(_jq '.id')

        echo "CLOSE CURRENT MILESTONE: $ID"

        BODY="{\"id\":\"$ID\",\"state_event\":\"close\"}"

        curl -H 'Content-Type:application/json' -H "PRIVATE-TOKEN:$TOKEN_GITLAB" -d "$BODY" -X PUT https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/milestones/$ID

    done
    ###
fi

### CREATE NEXT MILESTONE
BODY="{\"id\":\"$CI_PROJECT_ID\",\"title\":\"$NEXT_NEXT_TAG\"}"

curl -H 'Content-Type:application/json' -H "PRIVATE-TOKEN:$TOKEN_GITLAB" -d "$BODY" -X POST https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/milestones
###

if [ $FIRST_TAG == 0 ]; then
    # GENERATE RAW CHANGELOG
    COMMIT_LOG_FILE="tmp.txt"
    ISSUES_IDS_FILE="tmp2.txt"
    SORTED_UNIQUE_ISSUES_IDS="tmp3.txt"
    FINAL_LOG="tmp4.txt"
    CONTENT=""

    git fetch --tags
    git branch -D $RELEASE_BRANCH
    git pull origin $RELEASE_BRANCH
    git checkout $RELEASE_BRANCH

    touch $FINAL_LOG

    TAGS_COMP="$LATEST_TAG..$NEXT_TAG"

    REF_UPDATED=$(git log $TAGS_COMP --pretty=format:'%s' --grep='Update referential' --all-match)

    git log $TAGS_COMP --pretty=format:'%s' --grep='feat' --grep='Merge' --all-match >$COMMIT_LOG_FILE
    echo '' >>$COMMIT_LOG_FILE

    while IFS= read -r line; do
        ISSUE_ID=$(echo $line | grep -o 'feat/[0-9]*' | grep -o '[0-9]*')
        echo "$ISSUE_ID" >>$ISSUES_IDS_FILE
    done <"$COMMIT_LOG_FILE"

    git log $TAGS_COMP --pretty=format:'%s' --grep='fix' --grep='Merge' --all-match >$COMMIT_LOG_FILE
    echo '' >>$COMMIT_LOG_FILE

    while IFS= read -r line; do
        ISSUE_ID=$(echo $line | grep -o 'fix/[0-9]*' | grep -o '[0-9]*')
        echo "$ISSUE_ID" >>$ISSUES_IDS_FILE
    done <"$COMMIT_LOG_FILE"

    sort -u $ISSUES_IDS_FILE >$SORTED_UNIQUE_ISSUES_IDS

    while IFS= read -r line; do
        echo "=================="
        echo $line
        curl -H "X-Redmine-API-Key: ${REDMINE_API_KEY}" -H 'Content-Type: application/json' -X GET https://forge.maarch.org/issues/$line.json >issue_$line.json
        # echo `cat issue_$line.json`
        SUBJECT=$(cat issue_$line.json | jq -r '.issue.subject')
        TRACKER=$(cat issue_$line.json | jq -r '.issue.tracker.name')
        ID=$(cat issue_$line.json | jq -r '.issue.id')
        echo ""
        echo "ID : $ID"
        echo "TRACKER : $TRACKER"
        echo "SUBJECT : $SUBJECT"
        if [ ! -z $ID ]; then
            echo "* **$TRACKER [#$ID](https://forge.maarch.org/issues/$ID)** - $SUBJECT" >>$FINAL_LOG
        fi
        echo "=================="
    done <"$SORTED_UNIQUE_ISSUES_IDS"

    if [[ ! -z $REF_UPDATED ]]; then
        echo "* **Fonctionnalité** - Mise à jour de la BAN 75" >>$FINAL_LOG
    fi

    sort -u $FINAL_LOG >>changelog.txt

    while IFS= read -r line; do
        CONTENT="$CONTENT\n$line"
    done <"changelog.txt"

    echo $CONTENT

    # Replace all " by \" in $CONTENT
    CONTENT=${CONTENT//\"/\\\"}

    # Update tag release
    BODY="{\"description\":\"$CONTENT\",\"tag_name\":\"$NEXT_TAG\", \"milestones\": [\"$NEXT_TAG\"]}"

    curl -H 'Content-Type:application/json' -H "PRIVATE-TOKEN:$TOKEN_GITLAB" -d "$BODY" -X POST https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/releases

    mkdir -p ci/build/
    mv $COMMIT_LOG_FILE ci/build/
    mv $ISSUES_IDS_FILE ci/build/
    mv $SORTED_UNIQUE_ISSUES_IDS ci/build/
    mv $FINAL_LOG ci/build/
fi

RESPONSE=$(curl --write-out '%{url_effective} [%{response_code}]' --silent --output /dev/null --header "PRIVATE-TOKEN: $TOKEN_GITLAB" -X PUT "https://labs.maarch.org/api/v4/projects/$CI_PROJECT_ID/merge_requests/$MR_ID/merge?squash=false")
RESPONSE_CODE=$(echo $RESPONSE | grep -o '\[[0-9]*\]$')
if [ $RESPONSE_CODE != '[200]' ]; then
    echo "Error ! $RESPONSE"
    exit 1
fi
