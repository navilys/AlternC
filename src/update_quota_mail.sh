#!/bin/bash
. /usr/lib/alternc/functions.sh

#You can call this script either without arguments, inwich case each maildir quotas will be recalculated
#or you can call it with a directory reffering to a maildir to just sync one mailbox

function showhelp() {
    echo "$1"
    exit
}


# Generate the $maildirs list based on the arguments
while getopts "a:m:d:c:" optname
do
    case "$optname" in
        "a")
# All mails
            maildirs=$(mysql_query "select path from mailbox")
            ;;
        "m")
# An email 
            if [[ ! "$OPTARG" =~ ^[^\@]*@[^\@]*$ ]] ; then
                showhelp "bad mail address provided"
            fi
            if [[ ! "$(mysql_query "select userdb_home from dovecot_view where user = '$OPTARG'")" ]]; then
                showhelp "non existant mail address"
            fi
            maildirs=$(mysql_query "select userdb_home from dovecot_view where user = '$OPTARG'")
            ;;
        "d")
# Expecting a domain

# Check if domain is well-formed
            if [[ ! "$OPTARG" =~ ^[a-z0-9\-]+(\.[a-z\-]+)+$ ]] ; then
                showhelp "bad domain provided"
            fi

# Attemp to get from database.
            if [[ ! "$(mysql_query "select domaine from domaines where domaine = '$OPTARG'")" ]]; then
# Seem to be empty
                showhelp "non existant domain"
            fi

            maildirs=$(mysql_query "select userdb_home from dovecot_view where user like '%@$OPTARG'")
            ;;
        "c")
# An account
            if [[! "$OPTARG" =~ ^[a-z0-9]*$ ]] ; then
                showhelp "bad account provided"
            fi
            if [[! "$(mysql_query "select domaine from domaines where domaine = '$1'")" ]]; then
                showhelp "non existant account"
            fi
            maildirs=$(mysql_query "select userdb_home from dovecot_view where userdb_uid = $OPTARG")
            ;;
        "?")
            showhelp "Unknown option $OPTARG - stop processing"
            ;;
        ":")
            showhelp "No argument value for option $OPTARG - stop processing"
            ;;
        *)
# Should not occur
            echo
            showhelp  "Unknown error while processing options"
            ;;
    esac
done

# Now we have $maildirs, we can work on it

# FIXME add check if maildir is empty

#Then we loop through every maildir to get the maildir size
for i in $maildirs ; do

    if [ ! -d "$i" ];then
        echo "The maildir $i does not exists. It's quota won't be resync"
        continue
    fi

    if [ ! -d "$i" ];then
        echo "The maildir $i does not exists. It's quota won't be resync"
        continue
    fi

# We grep only mails, not the others files
    mails=`find $i -type f | egrep "(^$i)*[0-9]+\.M"`

# This part count the total mailbox size (mails + sieve scripts + ...)
    size=`du -b -s $i|awk '{print $1}'`

    mail_count=`echo $mails|wc -w`
    echo "folder : "$i
    echo "mail count : "$mail_count
    echo "dir size : "$size
    echo ""
#update the mailbox table accordingly
    mysql_query "UPDATE mailbox SET bytes=$size WHERE path='$i' ; "
    mysql_query "UPDATE mailbox SET messages=$mail_count WHERE path='$i' ; "
done

